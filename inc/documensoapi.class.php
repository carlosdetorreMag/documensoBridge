<?php


class PluginDocumensobridgeDocumensoAPI {

    /**
     * Realiza todas las llamadas necesarias para subir el archivo a Documenso con la firma adjunta
     * @param int $ticket
     * @param int $file_path
     * @return void
     */
    public static function sendToDocumenso(Ticket $ticket, $file_path) {

        $api_key = "api_ms8ai07aukoswdkw";
        $endpoint = "http://10.100.200.19:3000/api/v2/document/create";

        $payload = [
            "title"        => "Albaran_doc" . $ticket->fields['id'],
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
            
            if(!self::createRecipients($data['id'], $ticket->fields['users_id_recipient'])){
                return;
            }

            if(!self::designFields()){
                return;
            }
            self::storeDocumensoId($ticket->fields['id'], $data['id']);
        }
    }

    /**
     * Verifica que la función cree correctamente los recipientes necesarios
     * @param int $documenso_id
     * @param int $requester_id
     * @return bool
     */
    public static function createRecipients($documenso_id, $requester_id): bool{
        $api_key= "api_ms8ai07aukoswdkw";
        $endpoint = "http://10.100.200.19:3000/api/v2/recipient/create";

        $user= new User();
        $user->getFromDB($requester_id);

        $requester_fullname = $user->fields['firstname'] . " " . $user->fields['realname'];
        $requester_email= $user->fields['email'];


        Toolbox::logInFile(
            "documensobridge",
            "SECOND FUNCTION TRIGGERED\n"
        );
        
        return false;
            
        $payload = [
            "documentId" => $documensoId,
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
                "Authorization: Bearer $api_key"
            ],
            CURLOPT_POSTFIELDS => [
                "payload" => json_encode($payload),
                //"file" => new CURLFile($file_path, 'application/pdf')
            ]
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        
        if ($httpcode === 200) {
            $data = json_decode($response, true);
            
            self::createRecipients($data['id']);
            self::designFields();
            self::storeDocumensoId($ticket->fields['id'], $data['id']);
        }


        //$ch = curl_init();
    }

    public static function designFields(): bool{
        return false;
    }

    /**
     * Almacena un log en la tabla de la base de datos creada del plugin
     * @param int $ticket_id
     * @param int $documenso_id
     * @return void
     */
    private static function storeDocumensoId($ticket_id, $documenso_id) {

        global $DB;

        $query= "INSERT INTO `glpi_plugin_documensobridge_documents`
                       (`tickets_id`, `documenso_id`)
                VALUES ($ticket_id, $documenso_id);";
                       
        $DB->doQuery($query);
    }
}