<?php


class PluginDocumensobridgeDocumensoAPI {

    /**
     * Realiza todas las llamadas necesarias para subir el archivo a Documenso con la firma adjunta
     * @param Ticket $ticket Información relacionada con el ticket
     * @param string $file_path Ruta del archivo PDF subido con la categoría correspondiente
     * @param array $config Valor de la configuración del plugin
     * @param bool $observer Dice si se utiliza el usuario de request o de observer
     * @param int $document_id Identificador del documento del ticket.
     * @return void
     */
    public static function sendMaterialToDocumenso(Ticket $ticket, $file_path, $config, $observer, $document_id) {
        $env = parse_ini_file(__DIR__ . '/../.env');

        $api_key = $config["documenso_api_key"];

        if($api_key === NULL || $api_key ===""){
                Session::addMessageAfterRedirect(
                    htmlspecialchars(
                    __('Debes de especificar la conexión con documenso en la configuración del plugin.'),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                false,
                ERROR
            );
            return;
        }

        $endpoint = $env["DOC_SERVER"] . "" . $env["DOC_CREATE"];

        // Inserta la instancia en la tabla
        $plugin_id= self::insertPluginDocumentsTable($ticket->fields['id'], $document_id);
        $user_info= [];
        
        if(!self::obtainUserInfo($ticket, $observer, $user_info)){
            return;
        }

        $date = new DateTime();

        $payload = [
            "title"        => "GLPI-#" . $ticket->fields['id'] . '-' . $user_info["name"] . '-' . $date->format('dmY') . '-Albarán Material',
            "externalId"  => "GLPIALB_142221_" . $ticket->fields['id'] . "_" . $plugin_id
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
                
            if(!self::createRecipients($documenso_id, $env, $api_key, $user_info, $recipient_id)){
                return;
            }

            if(!self::designFields($documenso_id, $recipient_id, $config, $env, $user_info, $api_key)){
                return;
            }

            if(!self::distributeDocument($documenso_id, $env, $user_info, $api_key)){
                return;
            }

            self::updatePluginDocumentsTable($plugin_id, $documenso_id, $recipient_id, $user_info["id"]);

            Session::addMessageAfterRedirect(
                htmlspecialchars(
                    __('El archivo se envió a Documenso exitosamente.'),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                false,
                INFO
            );
        }

        else if($httpcode === 401){
            Session::addMessageAfterRedirect(
                htmlspecialchars(
                    __('La API KEY especificada en la configuración no es válida.'),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                false,
                ERROR
            );

            return;
        }

        else{
            Session::addMessageAfterRedirect(
                htmlspecialchars(
                    __('Hubo un error en la llamada al crear el documento en documenso. ERROR: '. $httpcode .''),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                false,
                ERROR
            );
        }
    }

    /**
     * Consigue la información del usuario que se va a utilizar para enviar el documento a firmar
     * @param int $documenso_id Id del documento de documenso
     * @param Ticket $ticket Objeto del ticket a utilizar de referencia
     * @param bool $observer Variable que determina si el usuario a enviar el documento es el observer o el requester
     * @param array|null $user_info Se rellena esta variable con toda la información del usuario
     * @return bool
     */
    public static function obtainUserInfo($ticket, $observer, &$user_info) {
        global $DB;    
    
        if(!$observer){
            $query_requester= "SELECT * FROM glpi_tickets_users WHERE tickets_id = '".$ticket->fields["id"]."' AND type = 1;";
            $result= $DB->doQuery($query_requester);
        }
        else{
            $query_observer= "SELECT * FROM glpi_tickets_users WHERE tickets_id = '".$ticket->fields["id"]."' AND type = 3;";
            $result= $DB->doQuery($query_observer);
        }
        
        // Número incorrecto de requesters/observers
        if ($DB->numrows($result)=== 0) {
            
            Session::addMessageAfterRedirect(
                htmlspecialchars(
                    __('Añade al menos un observador/solicitante para enviarle el documento.'),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                false,
                ERROR
            );
            return false;
        }

        if($DB->numrows($result) > 1){
            Session::addMessageAfterRedirect(
                htmlspecialchars(
                    __('Añade solo un solicitante/observador por ticket.'),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                false,
                ERROR
            );
            return false;
        }

        $user = $DB->fetchAssoc($result);
        $user_id = $user['users_id'];

        $query_user_info= "SELECT
                    u.id,
                    u.name, 
                    u.firstname, 
                    u.realname, 
                    e.email 
                FROM glpi_users AS u
                LEFT JOIN glpi_useremails AS e ON u.id = e.users_id
                WHERE u.id = '".$user_id."'";

        $result_user_info= $DB->doQuery($query_user_info);
        $user_info = $DB->fetchAssoc($result_user_info);
        
        return true;
    }

    /**
     * Consigue la información de los usuarios que se van a utilizar para enviar el documento a firmar
     * @param int $documenso_id Id del documento de documenso
     * @param Ticket $ticket Objeto del ticket a utilizar de referencia
     * @param array|null $requester_info Se rellena esta variable con toda la información del solicitante
     * @param array|null $observer_info Se rellena esta variable con toda la información del observador
     * @return bool
     */
    public static function obtainUsersInfo($ticket, &$requester_info, &$observer_info) {
        global $DB;    

        $query_requester= "SELECT * FROM glpi_tickets_users WHERE tickets_id = '".$ticket->fields["id"]."' AND type = 1;";
        $requester= $DB->doQuery($query_requester);

        $query_observer= "SELECT * FROM glpi_tickets_users WHERE tickets_id = '".$ticket->fields["id"]."' AND type = 3;";
        $observer= $DB->doQuery($query_observer);
        
        // Número incorrecto de requesters/observers
        if ($DB->numrows($requester)=== 0) {
            
            Session::addMessageAfterRedirect(
                htmlspecialchars(
                    __('Añade como mínimo un solicitante al ticket para enviar el documento para que firme cada uno.'),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                false,
                ERROR
            );
            return false;
        }

        // Número incorrecto de requesters/observers
        if ($DB->numrows($observer)=== 0) {
            
            Session::addMessageAfterRedirect(
                htmlspecialchars(
                    __('Añade como mínimo un observador al ticket para enviar el documento para que firme cada uno.'),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                false,
                ERROR
            );
            return false;
        }

        if($DB->numrows($requester) > 1){
            Session::addMessageAfterRedirect(
                htmlspecialchars(
                    __('Añade solo un solicitante por ticket.'),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                false,
                ERROR
            );
            return false;
        }

        if($DB->numrows($observer) > 1){
            Session::addMessageAfterRedirect(
                htmlspecialchars(
                    __('Añade solo un observador por ticket.'),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                false,
                ERROR
            );
            return false;
        }

        $requester_user = $DB->fetchAssoc($requester);
        $requester_user_id = $requester_user['users_id'];

        $observer_user = $DB->fetchAssoc($observer);
        $observer_user_id = $observer_user['users_id'];

        $query_requester_info= "SELECT
                    u.id,
                    u.name, 
                    u.firstname, 
                    u.realname, 
                    e.email 
                FROM glpi_users AS u
                LEFT JOIN glpi_useremails AS e ON u.id = e.users_id
                WHERE u.id = '".$requester_user_id."'";

        $result_requester_info= $DB->doQuery($query_requester_info);
        $requester_info = $DB->fetchAssoc($result_requester_info);

        $query_observer_info= "SELECT
                    u.id,
                    u.name, 
                    u.firstname, 
                    u.realname, 
                    e.email 
                FROM glpi_users AS u
                LEFT JOIN glpi_useremails AS e ON u.id = e.users_id
                WHERE u.id = '".$observer_user_id."'";

        $result_observer_info= $DB->doQuery($query_observer_info);
        $observer_info = $DB->fetchAssoc($result_observer_info);

        return true;
    }

    /**
     * Verifica que la función cree correctamente los recipientes necesarios
     * @param int $documenso_id Id del documento de documenso
     * @param array $env Variables como el endpoint y la api key (.env)
     * @param string $api_key El valor de la api key recogida en la configuración
     * @param bool $observer Variable que determina si el usuario a enviar el documento es el observer o el requester
     * @param array $user_info Variable que contiene toda la información del firmante.
     * @param int|null $recipient_id Id del recipiente creado en la función (output)
     * @return bool
     */
    public static function createRecipients($documenso_id, $env, $api_key, $user_info, &$recipient_id): bool{

        $user_email= $user_info['email'];
        $user_fullname = $user_info['firstname'] . " " . $user_info['realname'];

        $endpoint = $env["DOC_SERVER"] . "" . $env["DOC_RECIPIENT"];
                
        $body = [
            "documentId" => $documenso_id,
            "recipient" => [
                "email" => $user_email,
                "name" => $user_fullname,
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
        
        if ($httpcode === 200) {
            $data = json_decode($response, true);
            $recipient_id= $data["id"];
            return true;
        }

        Session::addMessageAfterRedirect(
            htmlspecialchars(
                __('Hubo un error en la llamada al crear el recipiente. ERROR: '. $httpcode .''),
                ENT_QUOTES,
                'UTF-8'
            ),
            false,
            ERROR
        );

        return false;
    }

    /**
     * Verifica que la función cree correctamente los recipientes necesarios
     * @param int $documenso_id Id del documento de documenso
     * @param array $env Variables como el endpoint y la api key (.env)
     * @param string $api_key El valor de la api key recogida en la configuración
     * @param array $requester_info Variable que contiene toda la información del solicitante.
     * @param array $observer_info Variable que contiene toda la información del observador.
     * @param int|null $recipient_id Id del recipiente creado en la función (output)
     * @return bool
     */
    public static function createRecipientsCertificate($documenso_id, $env, $api_key, $requester_info, $observer_info, &$recipient_ids): bool{

        $requester_email= $requester_info['email'];
        $requester_fullname = $requester_info['firstname'] . " " . $requester_info['realname'];
        $observer_email= $observer_info['email'];
        $observer_fullname = $observer_info['firstname'] . " " . $observer_info['realname'];

        $endpoint = $env["DOC_SERVER"] . "" . $env["DOC_RECIPIENT_MANY"];
                
        $body = [
            "documentId" => $documenso_id,
            "recipients" => [
                [
                    "email" => $requester_email,
                    "name" => $requester_fullname,
                    "role" => "SIGNER",
                    "signingOrder" => 1
                ],
                [
                    "email" => $observer_email,
                    "name" => $observer_fullname,
                    "role" => "SIGNER",
                    "signingOrder" => 2
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

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpcode === 200) {
            $data = json_decode($response, true);
            
            $recipient_ids = [
                "requester_id" => null,
                "observer_id" => null
            ];

            foreach ($data["recipients"] as $recipient) {
                if ((int)$recipient["signingOrder"] === 1) {
                    $recipient_ids["requester_id"] = $recipient["id"];
                }

                if ((int)$recipient["signingOrder"] === 2) {
                    $recipient_ids["observer_id"] = $recipient["id"];
                }
            }
            return true;
        }

        Session::addMessageAfterRedirect(
            htmlspecialchars(
                __('Hubo un error en la llamada al crear el recipiente. ERROR: '. $httpcode .''),
                ENT_QUOTES,
                'UTF-8'
            ),
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
     * @param array $user_info Información del usuario firmante.
     * @param string $api_key El valor de la api key recogida en la configuración
     * @return bool
     */
    public static function designFields($documenso_id, $recipient_id, $config, $env, $user_info, $api_key): bool{
        
        $user_fullname = $user_info['firstname'] . " " . $user_info['realname'];

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
            htmlspecialchars(
                __('Hubo un error en la llamada a crear campos. ERROR: '. $httpcode .''),
                ENT_QUOTES,
                'UTF-8'
            ),
            false,
            ERROR
        );

        return false;
    }

    /**
     * Función que permite generar el campo de firma
     * @param int $documenso_id Id del documento de documenso
     * @param array $recipient_ids Ids de los destinatarios de la firma
     * @param array $env Variables como el endpoint y la api key (.env)
     * @param array $requester_info Información del solicitante
     * @param array $observer_info Información del observador
     * @param string $api_key El valor de la api key recogida en la configuración
     * @return bool
     */
    public static function designFieldsCertificate($documenso_id, $recipient_ids, $env, $requester_info, $observer_info, $api_key): bool{
        
        $requester_fullname = $requester_info['firstname'] . " " . $requester_info['realname'];
        $observer_fullname = $observer_info['firstname'] . " " . $observer_info['realname'];

        $endpoint = $env["DOC_SERVER"] . "" . $env["DOC_FIELD_MANY"];

        $body = [
            "documentId" => $documenso_id,
            "fields" => [
                [
                    "height" => 8.66703081192736,
                    "pageNumber" => 2,
                    "pageX" => 12.34780701754388,
                    "pageY" => 80.15551214340576,
                    "recipientId" => $recipient_ids["requester_id"],
                    "type" => "SIGNATURE",
                    "width" => 35.27819548872179,
                    "fieldMeta" => [
                        "type" => "signature",
                        "label" => "Firma de $requester_fullname",
                        "placeholder" => "Firme aquí",
                        "required" => true,
                        "readOnly" => false,
                        "fontSize" => 12
                    ]
                ],
                [
                    "height" => 8.66703081192736,
                    "pageNumber" => 2,
                    "pageX" => 53.22650375939858,
                    "pageY" => 80.15551214340576,
                    "recipientId" => $recipient_ids["observer_id"],
                    "type" => "SIGNATURE",
                    "width" => 35.27819548872179,
                    "fieldMeta" => [
                        "type" => "signature",
                        "label" => "Firma de $observer_fullname",
                        "placeholder" => "Firme aquí",
                        "required" => true,
                        "readOnly" => false,
                        "fontSize" => 12
                    ] 
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
            htmlspecialchars(
                __('Hubo un error en la llamada a crear campos. ERROR: '. $httpcode .''),
                ENT_QUOTES,
                'UTF-8'
            ),
            false,
            ERROR
        );

        return false;
    }

    /**
     * Función que sube el documento a la plataforma preparado para que se firme.
     * @param int $documenso_id Id del documento de documenso
     * @param array $env Variables como el endpoint y la api key (.env)
     * @param array $user_info Contiene toda la información del usuario firmante.
     * @param string $api_key El valor de la api key recogida en la configuración
     * @param bool $certificado Indica si el documento es un certificado
     * @return bool
     */
    public static function distributeDocument($documenso_id, $env, $user_info, $api_key, $certificado = false): bool{
        $user_email= $user_info['email'];
        $user_fullname = $user_info['firstname'] . " " . $user_info['realname'];

        $subject= "Firma de albarán de " . $user_info['firstname'] . " " . $user_info['realname'];
        if($certificado){
            $subject= "Firma de certificados de " . $user_info['firstname'] . " " . $user_info['realname'];
        }

        $endpoint = $env["DOC_SERVER"] . "" . $env["DOC_DISTRIBUTE"];

        $body = [
            "documentId" => $documenso_id,
            "meta" => [
                "subject" => $subject,
                "timezone" => "Europe/Madrid",
                "dateFormat" => "yyyy-MM-dd HH:mm:ss",
                "distributionMethod" => "EMAIL",
                "redirectUrl" => $env["DOC_SERVER"],
                "language" => "es",
                "emailReplyTo" => null,
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
            htmlspecialchars(
                __('Hubo un error en la llamada a distribuir el documento. ERROR: '. $httpcode .''),
                ENT_QUOTES,
                'UTF-8'
            ),
            false,
            ERROR
        );

        return false;
    }

    /**
     * Almacena un log en la tabla de la base de datos creada del plugin
     * @param int $ticket_id Identificador del ticket
     * @param int $document_id Identificador del documento de GLPI asociado
     * @return int Devuelve el id de la fila insertada
     */
    private static function insertPluginDocumentsTable($ticket_id, $document_id) {

        global $DB;

        $query= "INSERT INTO `glpi_plugin_documensobridge_documents`
                       (`ticket_id`, `document_gpli_id`)
                VALUES ($ticket_id, $document_id);";
                       
        $DB->doQuery($query);

        return $DB->insertId();
    }

    /**
     * Almacena un log en la tabla de la base de datos creada del plugin
     * @param int $plugin_id Identificador de la instancia de la tabla del plugin concreta
     * @param int $documenso_id Identificador del documento de documenso asociado
     * @param int $recipient_signer_id Identidicador del recipiente del firmante
     * @param int $user_id Identificador del usuario firmante
     * @param bool $certificado Indica si el documento es un certificado
     * @return void
     */
    private static function updatePluginDocumentsTable($plugin_id, $documenso_id, $recipient_signer_id, $user_id, $certificado = false) {

        global $DB;

        $recipient_signer_db_id= $recipient_signer_id;

        if($certificado){
            $recipient_signer_db_id= $recipient_signer_id["requester_id"];
        }

        $query_update = "UPDATE `glpi_plugin_documensobridge_documents` 
                    SET documenso_id = '".$DB->escape($documenso_id)."', 
                        user_signer_id = '".$DB->escape($user_id)."',
                        recipient_signer_id = '".$DB->escape($recipient_signer_db_id)."'
                    WHERE id = '".$plugin_id."'";
        $DB->doQuery($query_update);

    }


    /**
     * Realiza todas las llamadas necesarias para subir el archivo a Documenso con la firma adjunta
     * @param Ticket $ticket Información relacionada con el ticket
     * @param string $file_path Ruta del archivo PDF subido con la categoría correspondiente
     * @param array $config Valor de la configuración del plugin
     * @param int $document_id Identificador del documento del ticket.
     * @return void
     */
    public static function sendCertificateToDocumenso(Ticket $ticket, $file_path, $config, $document_id) {
        $env = parse_ini_file(__DIR__ . '/../.env');

        $api_key = $config["documenso_api_key"];

        if($api_key === NULL || $api_key ===""){
                Session::addMessageAfterRedirect(
                    htmlspecialchars(
                    __('Debes de especificar la conexión con documenso en la configuración del plugin.'),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                false,
                ERROR
            );
            return;
        }

        $endpoint = $env["DOC_SERVER"] . "" . $env["DOC_CREATE"];

        // Inserta la instancia en la tabla
        $plugin_id= self::insertPluginDocumentsTable($ticket->fields['id'], $document_id);
        $requester_info= [];
        $observer_info= [];

        // Se almacena la información del solicitante del ticket
        if(!self::obtainUsersInfo($ticket, $requester_info, $observer_info)){
            return;
        }

        $date = new DateTime();

        $payload = [
            "title"        => "GLPI-#" . $ticket->fields['id'] . '-' . $requester_info["name"] . '-' . $date->format('dmY') . '-Certificado',
            "externalId"  => "GLPICERT_142221_" . $ticket->fields['id'] . "_" . $plugin_id
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
            $recipient_ids= [
                "requester_id" => null,
                "observer_id" => null
            ];

            if(!self::createRecipientsCertificate($documenso_id, $env, $api_key, $requester_info, $observer_info, $recipient_ids)){
                return;
            }

            if(!self::designFieldsCertificate($documenso_id, $recipient_ids, $env, $requester_info, $observer_info, $api_key)){
                return;
            }

            if(!self::distributeDocument($documenso_id, $env, $requester_info, $api_key, true)){
                return;
            }
            

            self::updatePluginDocumentsTable($plugin_id, $documenso_id, $recipient_ids, $requester_info["id"], true);

            Session::addMessageAfterRedirect(
                htmlspecialchars(
                    __('El archivo se envió a Documenso exitosamente.'),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                false,
                INFO
            );

            Toolbox::logInFile(
                "documensobridge",
                "Documento creado correctamente: " . $documenso_id . "\n"
            );
        }

        else if($httpcode === 401){
            Session::addMessageAfterRedirect(
                htmlspecialchars(
                    __('La API KEY especificada en la configuración no es válida.'),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                false,
                ERROR
            );

            return;
        }

        else{
            Session::addMessageAfterRedirect(
                htmlspecialchars(
                    __('Hubo un error en la llamada al crear el documento en documenso. ERROR: '. $httpcode .''),
                    ENT_QUOTES,
                    'UTF-8'
                ),
                false,
                ERROR
            );
        }
    }

}