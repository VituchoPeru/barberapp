<?php

declare(strict_types=1);

namespace App\Services\Panel;

use App\Core\Exceptions\ValidationException;
use App\Helpers\Validator;

/** Validación de los datos de un cliente de mostrador (sin cuenta) registrado desde el panel. */
final class ClientInput
{
    /**
     * @param array<string, mixed> $input nombres, apellidos, telefono, email
     * @return array{nombres:string, apellidos:?string, telefono:?string, email:?string}
     */
    public static function validate(array $input, bool $partial = false): array
    {
        if (isset($input['telefono']) && is_string($input['telefono'])) {
            $input['telefono'] = (string) preg_replace('/[\s\-().]/', '', $input['telefono']);
            if (preg_match('/^9\d{8}$/', $input['telefono'])) {
                $input['telefono'] = '+51' . $input['telefono']; // celular peruano sin código de país
            }
        }
        foreach (['telefono', 'email', 'apellidos'] as $k) {
            if (isset($input[$k]) && is_string($input[$k]) && trim($input[$k]) === '') {
                $input[$k] = null;
            }
        }
        $data = Validator::validate($input, [
            'nombres' => ($partial ? 'nullable' : 'required') . '|string|min:2|max:100',
            'apellidos' => 'nullable|string|max:100',
            'telefono' => 'nullable|regex:/^\+?[0-9]{6,15}$/',
            'email' => 'nullable|email',
        ]);
        $out = [
            'nombres' => isset($data['nombres']) ? trim((string) $data['nombres']) : '',
            'apellidos' => isset($data['apellidos']) && trim((string) $data['apellidos']) !== '' ? trim((string) $data['apellidos']) : null,
            'telefono' => $data['telefono'] ?? null,
            'email' => isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null,
        ];
        if (!$partial && mb_strlen($out['nombres']) < 2) {
            throw new ValidationException(['nombres' => ['Indica el nombre del cliente.']]);
        }
        return $out;
    }
}
