<?php

include_once __DIR__ . '/inc/config.class.php';

/**
 * Plugin install process
 *
 * @return boolean
 */
function plugin_documensobridge_install()
{
    global $DB;
    $category_name_req= NULL;
    $category_name_obs= NULL;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    // ==================================
    //    CREACIÓN DE TABLA DOCUMENTOS
    // ==================================
    if (!$DB->tableExists('glpi_plugin_documensobridge_documents')) {
        $query= "CREATE TABLE `glpi_plugin_documensobridge_documents` (
                `id` INT(11) {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `ticket_id` INT(11) {$default_key_sign} NOT NULL DEFAULT 0,
                `document_gpli_id` INT(11) {$default_key_sign} NOT NULL DEFAULT 0,
                `documenso_id` INT(11) {$default_key_sign} DEFAULT NULL,
                `user_signer_id` INT (11) {$default_key_sign} DEFAULT NULL,
                `recipient_signer_id` INT(11) {$default_key_sign} DEFAULT NULL,
                `state` VARCHAR(20) NOT NULL DEFAULT 'WAITING',
                `date_add` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `date_completed` TIMESTAMP DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `ticket_id` (`ticket_id`),
                KEY `user_signer_id` (`user_signer_id`),

                CONSTRAINT `fk_documensobridge_ticket`
                FOREIGN KEY (`ticket_id`)
                REFERENCES `glpi_tickets` (`id`)
                ON DELETE CASCADE
                ON UPDATE CASCADE,

                CONSTRAINT `fk_documensobridge_user_signer`
                FOREIGN KEY (`user_signer_id`)
                REFERENCES `glpi_users` (`id`)
                ON DELETE CASCADE
                ON UPDATE CASCADE,

                CONSTRAINT `fk_documensobridge_document_glpi`
                FOREIGN KEY (`document_gpli_id`)
                REFERENCES `glpi_documents` (`id`)
                ON DELETE CASCADE
                ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset};";

        $DB->doQuery($query);
    }

    // ====================================
    //    CREACIÓN DE CATEGORÍA REQUESTER
    // ====================================
    $category_comment_req= 'Esta es la categoria por defecto para subir los documentos a Documenso y se enlaza con el usuario del Solicitante. NO se debe modificar nada de la categoria fuera de la configuracion del plugin.';
    $query_check_req = "SELECT name FROM `glpi_documentcategories` WHERE comment = '".$DB->escape($category_comment_req)."'";
    $result_req = $DB->doQuery($query_check_req);

    // Crea la categoría si no existe ninguna con la misma descripción.
    if(!$DB->numrows($result_req)){
        $query_insert_req = "INSERT INTO `glpi_documentcategories` 
            (`name`, `comment`, `documentcategories_id`, `completename`, `level`, `ancestors_cache`, `sons_cache`, `date_creation`, `date_mod`)
            VALUES (
                'documenso_plugin_requester',                                                 -- name
                '".$DB->escape($category_comment_req)."',                                     -- comment
                0,                                                                            -- documentcategories_id
                'documenso_plugin_requester',                                                 -- completename
                1,                                                                            -- level
                NULL,                                                                         -- ancestors_cache
                '{}',                                                                         -- sons_cache
                NOW(),                                                                        -- date_creation
                NOW()                                                                         -- date_mod
            )";

        $DB->doQuery($query_insert_req);
    }

    // Si ya existe una instancia, coge el nombre de referencia para integrarlo en 
    // la tabla de configuración con ese nombre
    else if($DB->numrows($result_req) === 1){
        $requester = $DB->fetchAssoc($result_req);
        $category_name_req = $requester['name'];
    }

    // Se lanza un warning si hay dos categorías con la misma descripción.
    else{
        Session::addMessageAfterRedirect(
            __('Hay dos categorías con la misma descripción que la categoría del Solicitante. Intenta eliminar las categorías con la misma descripción hasta que solo quede una para evitar futuros errores.'),
            false,
            WARNING
        );
    }

    // ====================================
    //    CREACIÓN DE CATEGORÍA OBSERVER
    // ====================================
    $category_comment_obs= 'Esta es la categoria por defecto para subir los documentos a Documenso y se enlaza con el usuario del Observador. NO se debe modificar nada de la categoria fuera de la configuracion del plugin.';
    $query_check_obs = "SELECT name FROM `glpi_documentcategories` WHERE comment = '".$DB->escape($category_comment_obs)."'";
    $result_obs = $DB->doQuery($query_check_obs);

    // Crea la categoría si no existe ninguna con la misma descripción.
    if(!$DB->numrows($result_obs)){
        $query_insert_obs = "INSERT INTO `glpi_documentcategories` 
            (`name`, `comment`, `documentcategories_id`, `completename`, `level`, `ancestors_cache`, `sons_cache`, `date_creation`, `date_mod`)
            VALUES (
                'documenso_plugin_observer',                                                  -- name
                '".$DB->escape($category_comment_obs)."',                                     -- comment
                0,                                                                            -- documentcategories_id
                'documenso_plugin_observer',                                                  -- completename
                1,                                                                            -- level
                NULL,                                                                         -- ancestors_cache
                '{}',                                                                         -- sons_cache
                NOW(),                                                                        -- date_creation
                NOW()                                                                         -- date_mod
            )";

        $DB->doQuery($query_insert_obs);
    }

    // Si ya existe una instancia, coge el nombre de referencia para integrarlo en 
    // la tabla de configuración con ese nombre
    else if($DB->numrows($result_obs) === 1){
        $observer = $DB->fetchAssoc($result_obs);
        $category_name_obs = $observer['name'];
    }

    // Se lanza un warning si hay dos categorías con la misma descripción.
    else{
        Session::addMessageAfterRedirect(
            __('Hay dos categorías con la misma descripción que la categoría del Observador. Intenta eliminar las categorías con la misma descripción hasta que solo quede una para evitar futuros errores.'),
            false,
            WARNING
        );
    }

    // ====================================
    //     CREACIÓN DE CONFIGURACIÓN
    // ====================================
    PluginDocumensoBridgeConfig::install($category_name_req, $category_name_obs);

    return true;
}

/**
 * Plugin uninstall process
 *
 * @return boolean
 */
function plugin_documensobridge_uninstall()
{
    global $DB;

    // =======================================
    //    ELIMINACIÓN DE TABLA DE DOCUMENTOS
    // =======================================
    if ($DB->tableExists('glpi_plugin_documensobridge_documents')) {
        $query = 'DROP TABLE `glpi_plugin_documensobridge_documents`';
        $DB->doQuery($query);
    }

    // ==================================
    //    ELIMINACIÓN DE CONFIGURACIÓN
    // ==================================
    PluginDocumensoBridgeConfig::uninstall();

    return true;
}

/**
 * Función que comienza el flujo de ejecución del plugin
 * @param $document_item Contiene la información suficiente del documento
 * @return void
 */
function plugin_documensobridge_document_add($document_item) {
    global $DB;

    // Obtiene los datos de la configuración del plugin
    $query_config= "SELECT * FROM `glpi_plugin_documenso_bridgeconfigs` WHERE id = 1";
    $result_config= $DB->doQuery($query_config);
    $config_data = $DB->fetchAssoc($result_config);

    $observer= false;

    $category_name_req= $config_data["category_name_requester"];
    $category_name_obs= $config_data["category_name_observer"];

    // Si en la configuración hay un valor vacío se usa el valor predeterminado
    // ya que no se actualiza el valor vacío a la tabla de glpi_documentcategories
    if($config_data["category_name_requester"]=== ''){
        $category_name_req= "documenso_plugin_requester";
    }

    if($config_data["category_name_observer"]=== ''){
        $category_name_req= "documenso_plugin_observer";
    }

    // Verifica si se adjunta un archivo a un ticket
    if ($document_item->fields['itemtype'] !== 'Ticket') {
        return;
    }

    $ticket_id = $document_item->fields['items_id'];

    $document = new Document();
    $document->getFromDB($document_item->fields['documents_id']);

    // Busca la categoría de requester por el nombre y guarda el id
    $query_category_id = "SELECT id FROM `glpi_documentcategories` WHERE name = '".$DB->escape($category_name_req)."'";
    $result = $DB->doQuery($query_category_id);
    $row = $DB->fetchAssoc($result);
    $category_id = $row['id'];
    
    // Verificar que sea la categoría exacta del plugin buscando por id
    if($document->fields['documentcategories_id'] !== $category_id){
        $new_query_category_id = "SELECT id FROM `glpi_documentcategories` WHERE name = '".$DB->escape($category_name_obs)."'";
        $new_result = $DB->doQuery($new_query_category_id);
        $row = $DB->fetchAssoc($new_result);
        $category_id = $row['id'];

        // No es un error, es simplemente una subida de documento con una categoría que no es del plugin
        if($document->fields['documentcategories_id'] !== $category_id){
            return;
        }

        else{
            $observer= true;
        }
    }

    // Verificar que sea PDF
    if ($document->fields['mime'] !== 'application/pdf') {
        Session::addMessageAfterRedirect(
            __('El archivo adjunto debe de ser de tipo application/pdf para que se envíe a Documenso'),
            false,
            ERROR
        );
        return;
    }

    $file_path = GLPI_DOC_DIR . "/" . $document->fields['filepath'];

    // Cargar ticket
    $ticket = new Ticket();
    $ticket->getFromDB($ticket_id);

    // Llamar API
    include_once(__DIR__ . "/inc/documensoapi.class.php");

    PluginDocumensobridgeDocumensoAPI::sendToDocumenso($ticket, $file_path, $config_data, $observer, $document->fields["id"]);
}
