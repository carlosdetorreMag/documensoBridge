<?php


class PluginDocumensobridgeDocumensoAPI {

    /**
     * Realiza todas las llamadas necesarias para subir el archivo a Documenso con la firma adjunta
     * @param int $ticket
     * @param int $file_path
     * @param array $config Valor de la configuración del plugin
     * @return void
     */
    public static function sendToDocumenso(Ticket $ticket, $file_path, $config) {
        $env = parse_ini_file(__DIR__ . '/../.env');

        $api_key = $env["DOC_API_KEY"];
        $endpoint = $env["DOC_SERVER"] . "" . $env["DOC_CREATE"];

        $payload = [
            "title"        => "Albaran " . $ticket->fields['name'],
            "externalId"  => "GLPIALB_142221_" . $ticket->fields['id']
        ];

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $api_key"
            ],
            CURLOPT_POSTFIELDS => [
                "payload" => json_encode($payload),
                "file" => new CURLFile($file_path, 'application/pdf')
            ]
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpcode === 200) {
            $data = json_decode($response, true);

            $documenso_id= $data['id'];
            $recipient_id= null;
            $user_fullname= null;
            $user_email= null;
                
            if(!self::createRecipients($documenso_id, $ticket, $env, $recipient_id, $user_fullname, $user_email)){
                return;
            }

            if(!self::designFields($documenso_id, $recipient_id, $config, $env, $user_fullname)){
                return;
            }

            if(!self::distributeDocument($documenso_id, $env, $user_fullname, $user_email)){
                return;
            }

            self::storeDocumensoId($ticket->fields['id'], $documenso_id, $recipient_id);
        }

        else{
            Session::addMessageAfterRedirect(
                __('Hubo un error en la llamada al crear el documento en documenso'),
                false,
                ERROR
            );
        }
    }

    /**
     * Verifica que la función cree correctamente los recipientes necesarios
     * @param int $documenso_id Id del documento de documenso
     * @param Ticket $ticket Objeto del ticket a utilizar de referencia
     * @param array $env Variables como el endpoint y la api key (.env)
     * @param int|null $recipient_id Id del recipiente creado en la función (output)
     * @param string|null $user_fullname Nombre completo del usuario a rellenar (output)
     * @param string|null $user_email Email del usuario a rellenar (output)
     * @return bool
     */
    public static function createRecipients($documenso_id, $ticket, $env, &$recipient_id, &$user_fullname, &$user_email): bool{

        $api_key = $env["DOC_API_KEY"];
        $endpoint = $env["DOC_SERVER"] . "" . $env["DOC_RECIPIENT"];
        
        global $DB;
        
        $query_requester= "SELECT * FROM glpi_tickets_users WHERE tickets_id = '".$ticket->fields["id"]."' AND type = 1;";
        $result_req= $DB->doQuery($query_requester);

        // Número incorrecto de requesters/observers
        if ($DB->numrows($result_req)=== 0 || $DB->numrows($result_req) > 1) {
            // Elimina el documento que ha subido a documenso y devuelve error
            $endpoint = $env["DOC_SERVER"] . "" . $env["DOC_DELETE"];
            $body = ["documentId" => $documenso_id];

            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer $api_key",
                    "Content-Type: application/json"
                ],
                CURLOPT_POSTFIELDS => json_encode($body)
            ]);

            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            Session::addMessageAfterRedirect(
                __('Añade solo un requester/observer por ticket'),
                false,
                ERROR
            );
            return false;
        }

        $requester = $DB->fetchAssoc($result_req);
        $requester_id = $requester['users_id'];

        $query_requester_info= "SELECT 
                    u.firstname, 
                    u.realname, 
                    e.email 
                FROM glpi_users AS u
                LEFT JOIN glpi_useremails AS e ON u.id = e.users_id
                WHERE u.id = '".$requester_id."'";

        $result_req_info= $DB->doQuery($query_requester_info);
        $requester_info = $DB->fetchAssoc($result_req_info);    
        
        $requester_fullname = $requester_info['firstname'] . " " . $requester_info['realname'];
        $requester_email= $requester_info['email'];
                
        $body = [
            "documentId" => $documenso_id,
            "recipient" => [
                "email" => $requester_email,
                "name" => $requester_fullname,
                "role" => "SIGNER",
                "signingOrder" => 1
            ]
        ];

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $api_key",
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS => json_encode($body)
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $user_fullname= $requester_fullname;
        $user_email= $requester_email;
        
        if ($httpcode === 200) {
            $data = json_decode($response, true);
            $recipient_id= $data["id"];
            return true;
        }

        Session::addMessageAfterRedirect(
            __('Hubo un error en la llamada al crear el recipiente'),
            false,
            ERROR
        );

        return false;
    }

    /**
     * Función que permite generar el campo de firma
     * @param int $documenso_id Id del documento de documenso
     * @param int $recipient_id Id del recipiente del firmante
     * @param array $config Valor de la configuración del plugin
     * @param array $env Variables como el endpoint y la api key (.env)
     * @param string $user_fullname Nombre del usuario firmante
     * @return bool
     */
    public static function designFields($documenso_id, $recipient_id, $config, $env, $user_fullname): bool{

        $api_key = $env["DOC_API_KEY"];
        $endpoint = $env["DOC_SERVER"] . "" . $env["DOC_FIELD"];

        $body = [
            "documentId" => $documenso_id,
            "field" => [
                "height" => $config["height_value"],
                "pageNumber" => $config["page_value"],
                "pageX" => $config["posX_value"],
                "pageY" => $config["posY_value"],
                "recipientId" => $recipient_id,
                "type" => "SIGNATURE",
                "width" => $config["width_value"],
                "fieldMeta" => [
                    "type" => "signature",
                    "label" => "Firma de $user_fullname",
                    "placeholder" => "Firme aquí",
                    "required" => true,
                    "readOnly" => false,
                    "fontSize" => 12
                ]
            ]
        ];

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $api_key",
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS => json_encode($body)
        ]);

        $response= curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpcode === 200) {
            return true;
        }

        Session::addMessageAfterRedirect(
            __('Hubo un error en la llamada a crear campos'),
            false,
            ERROR
        );

        return false;
    }

    /**
     * Función que sube el documento a la plataforma preparado para que se firme.
     * @param int $documenso_id Id del documento de documenso
     * @param array $env Variables como el endpoint y la api key (.env)
     * @param string $user_fullname Nombre del usuario firmante
     * @param string $user_email Email del usuario firmante
     * @return bool
     */
    public static function distributeDocument($documenso_id, $env, $user_fullname, $user_email): bool{

        $api_key = $env["DOC_API_KEY"];
        $endpoint = $env["DOC_SERVER"] . "" . $env["DOC_DISTRIBUTE"];

        $body = [
            "documentId" => $documenso_id,
            "meta" => [
                "subject" => "Firma de albarán de $user_fullname",
                "timezone" => "Europe/Madrid",
                "dateFormat" => "yyyy-MM-dd HH:mm:ss",
                "distributionMethod" => "EMAIL",
                "redirectUrl" => $env["DOC_SERVER"],
                "language" => "es",
                "emailReplyTo" => $user_email,
                "emailSettings" => [
                    "recipientSigningRequest" => true,
                    "recipientRemoved" => true,
                    "recipientSigned" => true,
                    "documentPending" => true,
                    "documentCompleted" => true,
                    "documentDeleted" => true,
                    "ownerDocumentCompleted" => true
                ]
            ]
        ];

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $api_key",
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS => json_encode($body)
        ]);

        $response= curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpcode === 200) {
            return true;
        }

        Session::addMessageAfterRedirect(
            __('Hubo un error en la llamada a distribuir el documento'),
            false,
            ERROR
        );

        return false;
    }

    /**
     * Almacena un log en la tabla de la base de datos creada del plugin
     * @param int $ticket_id Identificador del ticket
     * @param int $documenso_id Identificador del documento de documenso asociado
     * @param int $recipient_requester_id Identidicador del recipiente del solicitante
     * @return void
     */
    private static function storeDocumensoId($ticket_id, $documenso_id, $recipient_requester_id) {

        global $DB;

        $query= "INSERT INTO `glpi_plugin_documensobridge_documents`
                       (`ticket_id`, `documenso_id`, `recipient_signer_id`)
                VALUES ($ticket_id, $documenso_id, $recipient_requester_id);";
                       
        $DB->doQuery($query);
    }
}