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
    public function verifyWebhook(Request $request)
    {
        //get
        $verifyToken = env('WHATSAPP_VERIFY_TOKEN');

        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        // Meta envía 'subscribe' cuando intentas registrar el webhook
        if ($mode === 'subscribe' && $token === $verifyToken) {
            // Meta exige que devuelvas exactamente el 'hub_challenge' como respuesta de texto plano
            return response($challenge, 200);
        }

        return response()->json(['error' => 'Token de verificación inválido'], 403);
    }

    /**
     * Recibe los mensajes de WhatsApp (Petición POST)
     */
    public function receiveMessage(Request $request)
    {
        //post
        //Log::info('Payload de WhatsApp:', $request->all());
        Log::info(json_encode($request->all()));

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
                    '521XXXXXXXXXX' => 'ALE' // Sustituir por el número real cuando lo tengas
                ];

                $remitente = $usuarios[$telefonoEntrante] ?? 'Desconocido';

                // 2. Parsear el Timestamp a la zona horaria correcta
                // Se formatea en 'd/m/Y' para coincidir exactamente con el formato de tu CSV
                $fechaActual = \Carbon\Carbon::createFromTimestamp($timestamp)
                    ->timezone('America/Mexico_City')
                    ->format('d/m/Y');

                // Para depurar, registramos los datos extraídos limpios
                Log::info("Mensaje procesado:", [
                    'remitente' => $remitente,
                    'fecha'     => $fechaActual,
                    'mensaje'   => $mensajeTexto
                ]);

                //NUEVO: Llamada a Gemini
                $datosProcesados = $this->consultarIA($mensajeTexto, $remitente, $fechaActual);

                if ($datosProcesados) {
                    Log::info("¡Éxito! JSON generado por la IA:", $datosProcesados);
                    // Mandar a Google Sheets 
                    $this->ejecutarAccionEnSheets($datosProcesados);
                }
            }
        }

        // Siempre debes responder con un 200 OK rápido para que Meta no reintente el envío
        return response('EVENT_RECEIVED', 200);
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
            // Usamos el modelo flash que es rapidísimo y muy barato/gratis para texto
            // $response = Gemini::gemini35Flash()
            //     ->withSystemInstruction($systemPrompt)
            //     ->generateContent($mensajeTexto);

            $response = Gemini::generativeModel(model: 'gemini-3.5-flash')->withSystemInstruction(Content::parse($systemPrompt))->generateContent($mensajeTexto);

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
            Log::error("Error al consultar a Gemini: " . $e->getMessage());
            return null;
        }
    }

    private function ejecutarAccionEnSheets(array $datos)
    {
        // 1. Configurar cliente de Google
        $client = new GoogleClient();
        $client->setApplicationName('Finanzas Pareja');
        $client->setScopes([GoogleSheets::SPREADSHEETS]);
        $client->setAuthConfig(storage_path('app/google-credentials.json'));

        $service = new GoogleSheets($client);
        $spreadsheetId = env('GOOGLE_SHEET_ID');
        $rango = 'Sheet1'; // Cambia esto si tu pestaña en Google Sheets se llama "Hoja 1" o diferente

        if ($datos['accion'] === 'insertar') {

            $monto = $datos['monto'] ?? 0;
            $gastoEmma = 0;
            $gastoAle = 0;

            // 2. Calcular la división según la regla de negocio
            if ($datos['tipo_gasto'] === 'COMPARTIDO') {
                $gastoEmma = $monto / 2;
                $gastoAle = $monto / 2;
            } else {
                if ($datos['quien_pago'] === 'EMMA') $gastoEmma = $monto;
                if ($datos['quien_pago'] === 'ALE') $gastoAle = $monto;
            }

            // 3. Obtener los datos actuales para saber si es un nuevo día
            $response = $service->spreadsheets_values->get($spreadsheetId, $rango);
            $valores = $response->getValues();
            $ultimaFila = end($valores);
            $ultimaFecha = $ultimaFila[0] ?? null; // La columna A es la fecha

            $filasAInsertar = [];

            // 4. Si la fecha es diferente, creamos el separador y cabeceras
            if ($ultimaFecha !== $datos['fecha']) {
                $filasAInsertar[] = ["", "", "", "", "", "", "", "", "", "", "", ""];
                $filasAInsertar[] = [
                    "Fecha", "Concepto", "Monto", "¿Quién pagó?", "Tipo de gasto",
                    "Gasto real Emma", "Gasto Real Ale", "", "Fecha",
                    "Balance diario EMMA", "Balance diario ALE", "Resumen del día"
                ];
            }

            // 5. Armamos la fila con los datos de este gasto
            $filasAInsertar[] = [
                $datos['fecha'],
                $datos['concepto'],
                $monto,
                $datos['quien_pago'],
                $datos['tipo_gasto'],
                $gastoEmma,
                $gastoAle
            ];

            // 6. Enviamos a Google Sheets
            $body = new \Google\Service\Sheets\ValueRange(['values' => $filasAInsertar]);
            $params = ['valueInputOption' => 'USER_ENTERED']; // Para que formatee números y fechas correctamente

            $service->spreadsheets_values->append($spreadsheetId, $rango, $body, $params);

            Log::info("Gasto insertado en Sheets exitosamente.");
        }
        elseif ($datos['accion'] === 'consultar') {
            // Aquí en un futuro puedes iterar sobre $valores para sumar los balances de $datos['fecha']
            Log::info("El usuario pidió consultar la fecha: " . $datos['fecha']);
        }
    }
}
