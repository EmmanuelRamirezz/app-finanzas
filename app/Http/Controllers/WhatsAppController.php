<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Gemini\Laravel\Facades\Gemini;
use Gemini\Data\Content;
use Google\Client as GoogleClient;
use Google\Service\Sheets as GoogleSheets;

class WhatsAppController extends Controller
{
    //Valida el webhook de Meta (Petición GET)
    public function verifyWebhook(Request $request)//get
    {
        $verifyToken = config('services.whatsapp.verify_token');

        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        // Meta envía 'subscribe' cuando intentas registrar el webhook
        if ($mode === 'subscribe' && $token === $verifyToken) {
            // Meta exige que devuelvas exactamente el 'hub_challenge' como respuesta de texto plano
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response()->json(['error' => 'Token de verificación inválido'], 403);
    }

    /**
     * Recibe los mensajes de WhatsApp (Petición POST)
     */
    public function receiveMessage(Request $request)
    {
        error_log('=== NUEVO WEBHOOK DE META ===');
        error_log(json_encode($request->all()));

        $data = $request->all();

        // Meta a veces envía actualizaciones de estado (entregado, leído),
        // así que primero validamos que el payload contenga un mensaje de texto.
        if (isset($data['entry'][0]['changes'][0]['value']['messages'][0])) {
            $messageData = $data['entry'][0]['changes'][0]['value']['messages'][0];

            // Ignorar audios, imágenes, etc. por ahora
            if ($messageData['type'] === 'text') {

                $telefonoEntrante = $messageData['from']; // Ej. "5213221895263"
                $mensajeTexto = $messageData['text']['body'];
                $timestamp = $messageData['timestamp'];

                // 1. Identificar quién envía el mensaje
                $usuarios = [
                    '5213221895263' => 'EMMA',
                    '5213311121184' => 'ALE' // Sustituir por el número real cuando lo tengas
                ];

                $remitente = $usuarios[$telefonoEntrante] ?? 'Desconocido';

                // 2. Parsear el Timestamp a la zona horaria correcta
                $fechaActual = \Carbon\Carbon::createFromTimestamp($timestamp)
                    ->timezone('America/Mexico_City')
                    ->format('d/m/Y');


                // 🛠️ NUEVO: Envolvemos el procesamiento en un bloque try-catch controlado
                try {

                    // Llamada a Gemini (La función que modificamos en el paso anterior)
                    $datosProcesados = $this->consultarIA($mensajeTexto, $remitente, $fechaActual);

                    if ($datosProcesados) {
                        // Mandar a Google Sheets
                        $this->ejecutarAccionEnSheets($datosProcesados);
                    }
                } catch (\Exception $e) {
                    error_log('>>> ERROR FATAL EN LA LÓGICA: ' . $e->getMessage());
                    // Verificamos si la excepción fue la de sobrecarga
                    if (str_contains($e->getMessage(), 'GEMINI_OVERLOAD')) {

                        Log::warning("Gemini saturado. Enviando mensaje de alerta a: " . $telefonoEntrante);

                        $mensajeAviso = "🤖 ¡Hola! En este momento hay una saturación temporal en los servidores de Inteligencia Artificial y no pude procesar tu gasto. Por favor, intenta reenviar tu mensaje en un par de minutos.";

                        // 🛡️ BLINDAJE NUEVO: Protegemos el envío de WhatsApp
                        try {
                            // ⚠️ IMPORTANTE: Asegúrate de que el método que usas para enviar
                            // mensajes se llame realmente así en tu controlador.
                            $this->enviarMensajeWhatsApp($telefonoEntrante, $mensajeAviso);
                            Log::info("Mensaje de aviso enviado correctamente por WhatsApp.");
                        } catch (\Throwable $th) {
                            // Si falla el envío de WhatsApp, lo registramos pero NO rompemos la ejecución
                            Log::error("Error crítico al intentar enviar el WhatsApp de aviso: " . $th->getMessage());
                        }

                    } else {
                        Log::error("Error inesperado en el procesamiento: " . $e->getMessage());
                    }
                }
                // 🛠️ FIN DEL TRATAMIENTO DE ERRORES

            }
        }

        // Siempre debes responder con un 200 OK rápido para que Meta no reintente el envío
        // Al capturar el error arriba, el código llegará hasta aquí sin insertar nada en Excel
        // y le dirá a Meta "Mensaje recibido, ya no lo reintentes".
        return response('EVENT_RECEIVED', 200);
    }
    private function enviarMensajeWhatsApp(string $numeroDestino, string $mensajeTexto)
    {
        // 1. Obtener credenciales (Asegúrate de tenerlas en tu archivo .env)
        $token = env('WHATSAPP_VERIFY_TOKEN');
        $phoneId = env('1672476820696747');
        $versionGraph = 'v25.0'; // Usa la versión de la API que te haya asignado Meta

        // 2. Construir la URL de la API de Meta
        $url = "https://graph.facebook.com/{$versionGraph}/{$phoneId}/messages";

        // 3. Armar el cuerpo del mensaje según la documentación de Meta
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $numeroDestino,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $mensajeTexto
            ]
        ];

        // 4. Enviar la petición HTTP a Meta
        try {
            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->post($url, $payload);

            if ($response->failed()) {
                \Illuminate\Support\Facades\Log::error("Meta Cloud API rechazó el envío: " . $response->body());
            }

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Error de conexión al enviar WhatsApp: " . $e->getMessage());
        }
    }

    private function consultarIA(string $mensajeTexto, string $remitente, string $fechaActual): ?array
    {
        $systemPrompt = "Eres un procesador de datos financieros para los gastos de EMMA y ALE.
        Tu única función es analizar el mensaje y devolver un objeto JSON estricto, sin texto adicional y sin formato markdown (sin bloques ```json).

        CONTEXTO ACTUAL:
        - Quien envía el mensaje es: {$remitente}. (Si el texto dice 'compré', 'pagué' o 'gasté', asume que fue {$remitente}).
        - La fecha actual es: {$fechaActual}. (Si el texto dice 'hoy', usa esta fecha).

        REGLAS DE EXTRACCIÓN:
        1. 'accion': Puede ser 'insertar' (para un nuevo gasto) o 'consultar' (para pedir un balance).
        2. 'fecha': Formato DD/MM/YYYY.
        3. 'concepto': Resumen corto de lo comprado, en MAYÚSCULAS (ej. PASTEL, CHETOS).
        4. 'monto': Solo el número (entero o decimal). Si no se menciona el precio, devuelve null.
        5. 'quien_pago': Estrictamente 'EMMA' o 'ALE'.
        6. 'tipo_gasto': Estrictamente 'COMPARTIDO' o 'INDIVIDUAL'.

        ESTRUCTURA JSON REQUERIDA:
        {
            \"accion\": \"insertar\",
            \"fecha\": \"DD/MM/YYYY\",
            \"concepto\": \"...\",
            \"monto\": 0.0,
            \"quien_pago\": \"...\",
            \"tipo_gasto\": \"...\"
        }";

        try {
            // Envolvemos SOLO la llamada a Gemini.
            // Argumentos: 3 intentos, la función a ejecutar, 2000ms de espera, y la condición para reintentar.
            $response = retry(
                3,
                function () use ($systemPrompt, $mensajeTexto) {
                    return Gemini::generativeModel(model: 'gemini-2.5-flash')
                        ->withSystemInstruction(Content::parse($systemPrompt))
                        ->generateContent($mensajeTexto);
                },
                2000,
                function (\Exception $e) {
                    // Esta es la condición: Solo reintenta si detecta alta demanda.
                    // Si es otro error, ignora el reintento y se va directo al bloque catch.
                    $mensajeError = $e->getMessage();
                    return str_contains($mensajeError, 'high demand') ||
                           str_contains($mensajeError, '503') ||
                           str_contains($mensajeError, 'Too Many Requests');
                }
            );

            // Limpiamos la respuesta en caso de que la IA agregue espacios o saltos de línea
            $textoLimpio = trim($response->text());

            // Si por alguna razón la IA incluyó las comillas de markdown, se las quitamos
            $textoLimpio = str_replace(['```json', '```'], '', $textoLimpio);

            $resultado = json_decode(trim($textoLimpio), true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $resultado;
            }

            Log::error("Gemini no devolvió un JSON válido. Respuesta cruda: " . $textoLimpio);
            return null;

        } catch (\Exception $e) {
            // Si llega a este punto, significa que ya falló las 3 veces,
            // o que fue un error distinto a la saturación.
            $mensajeError = $e->getMessage();

            if (
                str_contains($mensajeError, 'high demand') ||
                str_contains($mensajeError, '503') ||
                str_contains($mensajeError, 'Too Many Requests')
            ) {
                Log::warning("Gemini con alta demanda detectado tras 3 intentos. Relanzando excepción controlada.");
                throw new \Exception("GEMINI_OVERLOAD: " . $mensajeError);
            }

            // Si es cualquier otro error (ej. error de sintaxis, credenciales, etc.), lo registramos normal
            Log::error("Error al consultar a Gemini: " . $mensajeError);
            return null;
        }
    }

    private function ejecutarAccionEnSheets(array $datos)
    {
        // 1. Configurar cliente de Google
        // $client = new GoogleClient();
        // $client->setApplicationName('Finanzas Pareja');
        // $client->setScopes([GoogleSheets::SPREADSHEETS]);
        // $client->setAuthConfig(storage_path('app/google-credentials.json'));

        $client = new \Google_Client();
        $client->setApplicationName('App Finanzas');
        $client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);

        // 1. Leemos el secreto encriptado
        $base64Credentials = config('services.google.credentials');

        // 2. Lo decodificamos y lo convertimos de nuevo en un Array de PHP
        $credentialsArray = json_decode(base64_decode($base64Credentials), true);

        // 3. Autenticamos el cliente pasándole el Array en lugar del archivo físico
        $client->setAuthConfig($credentialsArray);

        $service = new \Google_Service_Sheets($client);

        //$service = new GoogleSheets($client);
        $spreadsheetId = config('services.google.spreadsheet_id');

        $nombreHoja = 'Sheet1'; // Asegúrate de que coincida con tu pestaña
        $rango = $nombreHoja;

        if ($datos['accion'] === 'insertar') {

            $monto = floatval($datos['monto'] ?? 0);
            $gastoEmma = 0;
            $gastoAle = 0;

            // 1. Calcular división de este gasto
            if ($datos['tipo_gasto'] === 'COMPARTIDO') {
                $gastoEmma = $monto / 2;
                $gastoAle = $monto / 2;
            } else {
                if ($datos['quien_pago'] === 'EMMA')
                    $gastoEmma = $monto;
                if ($datos['quien_pago'] === 'ALE')
                    $gastoAle = $monto;
            }

            // 2. Obtener datos históricos
            $response = $service->spreadsheets_values->get($spreadsheetId, $rango);
            $valores = $response->getValues() ?? [];
            $totalFilasPrevias = count($valores);

            $ultimaFecha = null;
            if ($totalFilasPrevias > 0) {
                $ultimaFila = end($valores);
                $ultimaFecha = $ultimaFila[0] ?? null;
            }

            $filasAInsertar = [];
            $esDiaNuevo = ($ultimaFecha !== $datos['fecha']);

            // 3. Determinar en qué fila va a empezar la inserción
            $filaInicioInsertar = $totalFilasPrevias + 1;
            $filaDestino = $filaInicioInsertar;

            // 4. Crear cabeceras si es un día nuevo
            if ($esDiaNuevo) {
                $filasAInsertar[] = ["", "", "", "", "", "", "", "", "", "", "", ""]; // Fila de separación
                $filasAInsertar[] = [
                    "Fecha",
                    "Concepto",
                    "Monto",
                    "¿Quién pagó?",
                    "Tipo de gasto",
                    "Gasto real Emma",
                    "Gasto Real Ale",
                    "",
                    "Fecha",
                    "Balance diario EMMA",
                    "Balance diario ALE",
                    "Resumen del día"
                ];
                $filaDestino += 2;
            }

            // 5. Fórmulas dinámicas
            $formulaEmma = '=SUMIFS(C:C, A:A, I' . $filaDestino . ', D:D, "EMMA") - SUMIFS(F:F, A:A, I' . $filaDestino . ')';
            $formulaAle = '=SUMIFS(C:C, A:A, I' . $filaDestino . ', D:D, "ALE") - SUMIFS(G:G, A:A, I' . $filaDestino . ')';
            $formulaResumen = '=IF(J' . $filaDestino . ' < 0, "EMMA DEBE A ALE $" & ABS(J' . $filaDestino . '), IF(J' . $filaDestino . ' > 0, "ALE DEBE A EMMA $" & J' . $filaDestino . ', "¡Están a mano hoy!"))';

            // 6. Armar fila de datos
            $filasAInsertar[] = [
                $datos['fecha'],
                $datos['concepto'],
                $monto,
                $datos['quien_pago'],
                $datos['tipo_gasto'],
                $gastoEmma,
                $gastoAle,
                "",
                $datos['fecha'],
                $formulaEmma,
                $formulaAle,
                $formulaResumen
            ];

            // 7. Enviar los valores (Datos) a Sheets
            $body = new \Google\Service\Sheets\ValueRange(['values' => $filasAInsertar]);
            $params = ['valueInputOption' => 'USER_ENTERED'];

            $filasEnviadas = count($filasAInsertar);
            $filaFinInsertar = $filaInicioInsertar + ($filasEnviadas - 1);

            $rangoExacto = "'{$nombreHoja}'!A{$filaInicioInsertar}:L{$filaFinInsertar}";
            $service->spreadsheets_values->update($spreadsheetId, $rangoExacto, $body, $params);

            // 8. APLICAR DISEÑO (Colores pastel y Bordes) si es un día nuevo
            if ($esDiaNuevo) {
                // Generar un color aleatorio en tono pastel (RGB entre 0.85 y 1.0)
                $r = mt_rand(85, 100) / 100;
                $g = mt_rand(85, 100) / 100;
                $b = mt_rand(85, 100) / 100;

                // La fila de la cabecera será la fila de inicio + 1
                // Nota: La API de diseño usa índices basados en 0 (la fila 1 es el índice 0)
                $indiceFilaCabecera = $filaInicioInsertar; // Esto apunta a la fila de la cabecera (excluye la vacía)
                $indiceFilaFin = $filaFinInsertar;       // Hasta el final de los datos insertados

                $requests = [
                    new \Google\Service\Sheets\Request([
                        'repeatCell' => [
                            'range' => [
                                'sheetId' => 0, // ⚠️ 0 es el ID por defecto de la primera pestaña (Sheet1)
                                'startRowIndex' => $indiceFilaCabecera,
                                'endRowIndex' => $indiceFilaFin,       // Exclusivo
                                'startColumnIndex' => 0,               // Columna A
                                'endColumnIndex' => 12                 // Columna L (Exclusivo)
                            ],
                            'cell' => [
                                'userEnteredFormat' => [
                                    'backgroundColor' => [
                                        'red' => $r,
                                        'green' => $g,
                                        'blue' => $b
                                    ],
                                    'borders' => [
                                        'top' => ['style' => 'SOLID'],
                                        'bottom' => ['style' => 'SOLID'],
                                        'left' => ['style' => 'SOLID'],
                                        'right' => ['style' => 'SOLID']
                                    ]
                                ]
                            ],
                            'fields' => 'userEnteredFormat(backgroundColor,borders)'
                        ]
                    ])
                ];

                $batchUpdateRequest = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
                    'requests' => $requests
                ]);

                // Ejecutar el diseño
                $service->spreadsheets->batchUpdate($spreadsheetId, $batchUpdateRequest);
            }

            Log::info("Gasto insertado y diseñado exitosamente en la fila: " . $filaDestino);
        } elseif ($datos['accion'] === 'consultar') {
            Log::info("El usuario pidió consultar la fecha: " . $datos['fecha']);
        }
    }
}
