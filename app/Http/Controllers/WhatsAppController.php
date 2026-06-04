<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

                // 3. AQUI IRÁ LA CONEXIÓN A LA IA (Siguiente paso)
                // $respuestaIA = $this->consultarIA($mensajeTexto, $remitente, $fechaActual);
                // $this->ejecutarAccionEnSheets($respuestaIA);
            }
        }

        // Siempre debes responder con un 200 OK rápido para que Meta no reintente el envío
        return response('EVENT_RECEIVED', 200);
    }
}
