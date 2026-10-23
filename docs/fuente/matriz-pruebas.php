<?php

declare(strict_types=1);

/*
 * Matriz de pruebas: cada requisito del prompt maestro con las pruebas que lo verifican.
 * Referencias:
 *   'Booking/BookingApiTest::testMetodo'  → backend/tests/{Unit|Integration}/…
 *   'Booking/ConcurrencyTest'             → toda la clase
 *   'e2e:reserva.spec.mjs:título'         → prueba de Playwright con ese título
 *   'e2e:pantallas.spec.mjs'              → todo el archivo
 *   'ci:despliegue'                       → trabajo de .github/workflows/calidad.yml
 * `php bin/console docs:generar` comprueba que cada referencia exista y arma
 * docs/MATRIZ_PRUEBAS.md; si una prueba se renombra o se borra, la generación falla.
 */
return [
    [
        'titulo' => 'Fase 5: pruebas obligatorias del motor de reservas',
        'filas' => [
            'Dos clientes intentan reservar el mismo horario' => ['Booking/ConcurrencyTest::testTwoClientsBookTheSameSlotAtTheSameTime'],
            'Un cliente cancela' => ['Booking/BookingApiTest::testCancelReleasesTheSlotAndAnotherClientBooksIt', 'e2e:reserva.spec.mjs:reserva desde el enlace de la barbería y la cancela'],
            'Otro cliente reserva el intervalo liberado' => ['Booking/BookingApiTest::testCancelReleasesTheSlotAndAnotherClientBooksIt', 'Booking/ConcurrencyTest::testCancellationAndNewBookingAtTheSameTime'],
            'Se bloquea un día completo' => ['Booking/BookingApiTest::testFullDayBlockRemovesAvailability', 'Catalog/AgendaBlocksApiTest::testFullDayVacationRemovesWorkingTimeAndDeletingRestoresIt', 'ScheduleUnitTest::testFullDayBlockAndBranchBlocksAndValidity'],
            'Un barbero cambia su jornada' => ['Booking/BookingApiTest::testBarberScheduleChangeIsReflectedImmediately', 'Catalog/BarbersApiTest::testChangingScheduleWarnsAboutBookingsOutsideIt'],
            'Un servicio dura 45 minutos' => ['Booking/BookingApiTest::testFortyFiveMinuteServiceOnlyOffersSlotsThatFit', 'AvailabilityUnitTest::testFortyFiveMinuteServiceNeverCrossesABreak', 'AvailabilityUnitTest::testBusyIntervalsAndTheGapLeftAfterAFortyFiveMinuteBooking'],
            'Un cliente solicita varios servicios' => ['Booking/BookingApiTest::testSeveralServicesAreConsecutiveWithTheSameBarber', 'AvailabilityUnitTest::testCombinedServicesUseTheBarbersOwnDurationAndPrice'],
            'Se intenta reservar en otro tenant' => ['Booking/BookingApiTest::testCannotBookOrReadAcrossTenants', 'ConstraintsTest::testReservationCannotMixTenants'],
            'Una petición se repite por problemas de red' => ['Booking/BookingApiTest::testRetryWithSameIdempotencyKeyDoesNotDuplicate', 'Booking/ConcurrencyTest::testNetworkRetryWithTheSameKeyArrivingTwiceAtOnce'],
            'Dos reservas simultáneas ocupan parcialmente el mismo intervalo' => ['Booking/ConcurrencyTest::testPartiallyOverlappingRequestsAtTheSameTime', 'Booking/BookingApiTest::testPartiallyOverlappingBookingIsRejectedWithAlternatives', 'AvailabilityUnitTest::testPartialOverlapIsNeverOffered'],
        ],
    ],
    [
        'titulo' => 'Fase 14: unitarias',
        'filas' => [
            'Cálculo de duración' => ['AvailabilityUnitTest::testThirtyMinuteServiceOnFifteenMinuteGridFitsInsideWindow', 'AvailabilityUnitTest::testCombinedServicesUseTheBarbersOwnDurationAndPrice', 'ScheduleUnitTest::testServiceDurationAndPriceRules'],
            'Cálculo de disponibilidad' => ['AvailabilityUnitTest', 'ScheduleUnitTest::testWorkingWindowsSubtractBreaksAndBlocks', 'ScheduleUnitTest::testBranchTimezoneIsRespected'],
            'Validación de políticas' => ['BookingRulesTest::testClientCancellationWindow', 'BookingRulesTest::testClientRescheduleWindowAndLimit', 'BookingRulesTest::testMinimumNoticeAndHorizonUseTheBranchTimezone', 'AvailabilityUnitTest::testPolicyDefaultsAndText'],
            'Control de estados' => ['BookingRulesTest::testStatesMatchTheDatabaseEnum', 'BookingRulesTest::testStaffTransitionMatrixDuringTheAppointment', 'BookingRulesTest::testStartedAttentionAndPaymentsBlockNoShow'],
        ],
    ],
    [
        'titulo' => 'Fase 14: integración',
        'filas' => [
            'Registro' => ['Auth/RegisterTest'],
            'Login' => ['Auth/LoginSessionTest', 'Auth/PasswordRecoveryTest'],
            'Creación de barbería' => ['Auth/RegisterTest::testPropietarioRegistersWithBarberiaPendingApproval', 'Platform/PlatformTenantsTest::testApprovePendingTenantNotifiesOwner', 'FullJourneyTest::testFromRegistrationToCancellation'],
            'Creación de servicios' => ['Catalog/ServicesApiTest::testOwnerCreatesListsAndEditsServices', 'Catalog/BarbersApiTest::testOwnerRegistersBarberWithServicesAndSchedule', 'Catalog/BranchesApiTest::testOwnerCreatesBranchWithUniqueSlugAndHours'],
            'Creación de reservas' => ['Booking/BookingApiTest', 'Panel/PanelAgendaTest::testManualBookingWithWalkInClientUsesTheSameEngine'],
            'Cancelaciones' => ['Booking/BookingApiTest::testCancelReleasesTheSlotAndAnotherClientBooksIt', 'Panel/PanelAgendaTest::testStaffCancelAndRescheduleToAnotherBarber'],
            'Reprogramaciones' => ['Booking/BookingApiTest::testRescheduleKeepsTheOldSlotUntilTheNewOneIsConfirmed', 'Panel/PanelAgendaTest::testStaffCancelAndRescheduleToAnotherBarber'],
            'Notificaciones' => ['Notifications/ReminderQueueTest', 'Notifications/NotificationsApiTest'],
        ],
    ],
    [
        'titulo' => 'Fase 14: concurrencia (procesos PHP reales contra MySQL)',
        'filas' => [
            'Reservas simultáneas' => ['Booking/ConcurrencyTest::testTwoClientsBookTheSameSlotAtTheSameTime', 'Booking/ConcurrencyTest::testThreeClientsChooseAnyBarberForTwoFreeBarbers'],
            'Cancelación y nueva reserva simultáneas' => ['Booking/ConcurrencyTest::testCancellationAndNewBookingAtTheSameTime'],
            'Reintentos duplicados' => ['Booking/ConcurrencyTest::testNetworkRetryWithTheSameKeyArrivingTwiceAtOnce', 'Notifications/ReminderQueueTest::testDedupeKeyPreventsDuplicatesOnRetriedRequests'],
        ],
    ],
    [
        'titulo' => 'Fase 14: seguridad',
        'filas' => [
            'Acceso entre tenants' => ['Security/ForeignResourceTest', 'Security/AccessMatrixTest::testEveryRouteGrantsExactlyTheExpectedAccessPerRole', 'TenantContextMiddlewareTest::testUserCannotEnterAnotherTenantsPanel', 'ConstraintsTest::testCannotAssignServiceOfAnotherTenantToBarber'],
            'Escalada de privilegios' => ['Security/AccessMatrixTest::testMatrixInvariants', 'Auth/AuthorizationTest::testOwnersCannotInviteOwnersOrPlatformRoles', 'Auth/AuthorizationTest::testSuperadminHasNoImplicitAccessToBarberias'],
            'SQL Injection' => ['Security/InjectionTest::testNoRouteIsInjectableThroughQueryBodyOrTenantHeader', 'Security/InjectionTest::testSearchAndLoginTreatPayloadsAsPlainText'],
            'XSS' => ['Security/InjectionTest::testStoredMarkupIsReturnedAsJsonDataNotHtml', 'e2e:pantallas.spec.mjs', 'e2e:reserva.spec.mjs:reserva desde el enlace de la barbería y la cancela'],
            'CSRF' => ['Security/AccessMatrixTest::testEveryWriteWithCookieSessionRequiresCsrf', 'Security/AccessMatrixTest::testCsrfTokenFromAnotherSessionIsRejected', 'Security/HttpHardeningTest::testWritesFromForeignOriginsAreRejected'],
            'Acceso a reservas ajenas' => ['Security/ForeignResourceTest::testNoRouteReachesAnotherPersonsOrBarbershopsResourceById', 'Barber/BarberPanelTest::testBarberAttendsOwnBookingsAndCannotTouchOthers', 'Panel/PanelAgendaTest::testOtherTenantCannotSeeOrTouchBookings'],
        ],
    ],
    [
        'titulo' => 'Fase 14: multiplataforma (Playwright; Safari y Firefox solo en CI)',
        'filas' => [
            'Chrome' => ['e2e:pantallas.spec.mjs', 'e2e:reserva.spec.mjs:reserva desde el enlace de la barbería y la cancela'],
            'Safari' => ['e2e:pantallas.spec.mjs', 'ci:frontend'],
            'Android' => ['e2e:pantallas.spec.mjs', 'e2e:reserva.spec.mjs:reserva desde el enlace de la barbería y la cancela'],
            'iOS' => ['e2e:pantallas.spec.mjs', 'ci:frontend'],
            'Pantallas pequeñas' => ['e2e:pantallas.spec.mjs'],
            'Tablets' => ['e2e:pantallas.spec.mjs'],
            'Escritorio' => ['e2e:pantallas.spec.mjs', 'e2e:reserva.spec.mjs:la agenda muestra las citas del día con su estado'],
        ],
    ],
    [
        'titulo' => 'Fase 2: criterios de aceptación de autenticación',
        'filas' => [
            'Un usuario puede registrarse' => ['Auth/RegisterTest::testClienteRegistersAndIsLoggedInWithWebSession'],
            'Puede iniciar y cerrar sesión' => ['Auth/LoginSessionTest::testWebLoginLogoutAndCsrf', 'Auth/LoginSessionTest::testMobileAccessTokenAndRefreshRotation'],
            'Puede recuperar su contraseña' => ['Auth/PasswordRecoveryTest::testFullPasswordResetFlowClosesAllSessions'],
            'Solo accede a recursos autorizados' => ['Security/AccessMatrixTest::testEveryRouteGrantsExactlyTheExpectedAccessPerRole', 'Auth/LoginSessionTest::testProtectedEndpointsRequireAuthentication'],
            'No puede consultar datos de otro tenant' => ['TenantContextMiddlewareTest::testUserCannotEnterAnotherTenantsPanel', 'Auth/AuthorizationTest::testCannotSelectOrEnterAnotherTenant'],
            'Los intentos no autorizados quedan registrados' => ['Security/AccessMatrixTest::testEveryWriteWithCookieSessionRequiresCsrf', 'Auth/LoginSessionTest::testLoginIsLimitedAfterFiveFailures'],
        ],
    ],
    [
        'titulo' => 'Fase 4: reglas de servicios, barberos y horarios',
        'filas' => [
            'No permitir duración cero' => ['ConstraintsTest::testServiceRejectsZeroDurationAndNegativePrice', 'Catalog/ServicesApiTest::testRulesForDurationPriceAndName'],
            'No permitir precios negativos' => ['ConstraintsTest::testServiceRejectsZeroDurationAndNegativePrice', 'Catalog/ServicesApiTest::testRulesForDurationPriceAndName'],
            'No mostrar servicios inactivos' => ['Catalog/ServicesApiTest::testInactiveServiceDisappearsFromMarketplace'],
            'No ofrecer horarios fuera de jornada' => ['ScheduleUnitTest::testWorkingWindowsSubtractBreaksAndBlocks', 'Catalog/BarbersApiTest::testWorkingTimeHonoursBreaksAndApprovedVacations'],
            'No ofrecer intervalos bloqueados' => ['Catalog/AgendaBlocksApiTest::testPartialBlockAndBranchWideHoliday', 'Booking/BookingApiTest::testFullDayBlockRemovesAvailability'],
            'Un barbero no puede estar en dos sucursales a la vez' => ['ScheduleUnitTest::testBarberCannotWorkInTwoBranchesAtTheSameTime'],
        ],
    ],
    [
        'titulo' => 'Criterios de éxito del MVP (sección 25)',
        'filas' => [
            'Una barbería puede registrarse' => ['Auth/RegisterTest::testPropietarioRegistersWithBarberiaPendingApproval', 'FullJourneyTest::testFromRegistrationToCancellation'],
            'El propietario puede configurar su negocio' => ['Marketplace/PublicProfileTest::testOwnerReadsAndUpdatesProfile', 'Booking/BookingApiTest::testPanelBookingPolicyIsConfigurable'],
            'Puede crear servicios y barberos' => ['Catalog/ServicesApiTest::testOwnerCreatesListsAndEditsServices', 'Catalog/BarbersApiTest::testOwnerRegistersBarberWithServicesAndSchedule'],
            'Puede definir horarios' => ['Catalog/BarbersApiTest::testScheduleRules', 'Catalog/BranchesApiTest::testOwnerCreatesBranchWithUniqueSlugAndHours'],
            'Un cliente puede encontrar la barbería' => ['Marketplace/MarketplaceApiTest::testSearchByNameDistrictAndServiceIsAccentInsensitive', 'Marketplace/MarketplaceApiTest::testDirectLinkWorksForUnlistedButNotForSuspended'],
            'Puede reservar por marketplace o enlace directo' => ['e2e:reserva.spec.mjs:reserva desde el enlace de la barbería y la cancela', 'FullJourneyTest::testFromRegistrationToCancellation'],
            'El motor impide reservas solapadas' => ['Booking/ConcurrencyTest', 'AvailabilityUnitTest::testPartialOverlapIsNeverOffered'],
            'El administrador visualiza las reservas' => ['Panel/PanelAgendaTest::testOwnerSeesDayAgendaWithBarbersWorkingHoursAndStates', 'e2e:reserva.spec.mjs:la agenda muestra las citas del día con su estado'],
            'El cliente puede cancelar o reprogramar' => ['Booking/BookingApiTest::testCancellationAndRescheduleWindowsAndLimits', 'Booking/BookingApiTest::testRescheduleKeepsTheOldSlotUntilTheNewOneIsConfirmed'],
            'Los horarios cancelados vuelven a estar disponibles' => ['Booking/BookingApiTest::testCancelReleasesTheSlotAndAnotherClientBooksIt'],
            'Se envían recordatorios programados' => ['Notifications/ReminderQueueTest::testWorkerSendsRemindersWhenDueEvenWithTheAppClosed'],
            'El barbero puede consultar su agenda' => ['Barber/BarberPanelTest::testDayAgendaShowsOnlyOwnBookingsWithWorkingHoursAndNoCashData'],
            'Cada empresa tiene aislamiento de datos' => ['Security/ForeignResourceTest', 'ConstraintsTest::testReservationCannotMixTenants'],
            'El superadministrador gestiona empresas y planes' => ['Platform/PlatformTenantsTest', 'Platform/PlatformBillingTest::testPlanValidationAndCreation'],
            'Funciona en web y se empaqueta para Android e iOS' => ['e2e:pantallas.spec.mjs', 'ci:frontend'],
        ],
    ],
    [
        'titulo' => 'Fases 13 y 15: datos personales, copias y despliegue',
        'filas' => [
            'Eliminar la cuenta y borrar los datos personales' => ['Privacy/AccountDeletionTest::testClientDeletesAccountAndPersonalDataIsErased'],
            'Descargar mis datos (acceso)' => ['Privacy/AccountDataTest::testExportContainsOnlyOwnDataAndIsRecorded'],
            'Solicitudes ARCO' => ['Privacy/DataRequestsTest::testClientSubmitsAndOperatorAnswers'],
            'Retención de datos' => ['Privacy/RetentionTest'],
            'Copias cifradas y restauración probada' => ['Security/BackupTest::testEncryptedBackupRestoresWithTheSameRowsAndBinaryData', 'Security/BackupTest::testTamperedOrWrongKeyCopiesAreRejected'],
            'Copias fuera del servidor (S3)' => ['Ops/OffsiteBackupTest'],
            'Monitoreo, mantenimiento y latidos' => ['Ops/OperationsTest'],
            'Instalación en un VPS limpio, despliegue y rollback' => ['ci:despliegue'],
            'La documentación sigue al día con el código' => ['Docs/DocumentationTest'],
        ],
    ],
];
