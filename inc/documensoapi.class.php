<?php


class PluginDocumensobridgeDocumensoAPI {

    /**
     * Realiza todas las llamadas necesarias para subir el archivo a Documenso con la firma adjunta
     * @param int $ticket
     * @param int $file_path
     * @param mysqli_result $config Valor de la configuración del plugin
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
            
            if(!self::createRecipients($data['id'], $ticket, $config)){
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
     * @param Ticket $ticket
     * @param mysqli_result $config Valor de la configuración del plugin
     * @return bool
     */
    public static function createRecipients($documenso_id, $ticket, $config): bool{
        $env = parse_ini_file(__DIR__ . '/../.env');

        $api_key = $env["DOC_API_KEY"];
        $endpoint = $env["DOC_SERVER"] . "" . $env["DOC_RECIPIENT"];
        
        global $DB;
        
        $query_requester= "SELECT * FROM glpi_tickets_users WHERE tickets_id = '".$ticket->fields["id"]."' AND type = 1;";
        $result_req= $DB->doQuery($query_requester);

        if ($DB->numrows($result_req)== 0 || $DB->numrows($result_req) > 1) {
            Toolbox::logInFile(
                "documensobridge",
                "ONLY ONE REQUESTER/OBSERVER FOR EACH TICKET\n"
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

        Toolbox::logInFile(
            "documensobridge",
            "ID: " . $requester_id . " USER NAME: " . $requester_info['firstname'] . " " . $requester_info['realname'] . "USER EMAIL: " . $requester_info['email'] . "\n"
        );    
        
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

        
        if ($httpcode === 200) {
            $data = json_decode($response, true);
            Toolbox::logInFile(
                "documensobridge",
                "Second Function success\n"
            );  
            return true;
        }

        Toolbox::logInFile(
            "documensobridge",
            "ERROR Second Function\n"
        ); 

        return false;
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