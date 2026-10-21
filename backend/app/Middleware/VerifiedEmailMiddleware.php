<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;

/** Exige correo verificado (acciones sensibles: invitar personal, operar una barbería…). */
final class VerifiedEmailMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $user = $request->attribute('auth_user');
        if (!is_array($user) || empty($user['email_verificado_en'])) {
            throw new HttpException(403, 'email_not_verified', 'Confirma tu correo electrónico para continuar. Revisa tu bandeja de entrada.');
        }
        return $next($request);
    }
}
