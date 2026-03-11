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

    // ==================================
    //    CREACIÓN DE TABLA DOCUMENTOS
    // ==================================
    if (!$DB->tableExists('glpi_plugin_documensobridge_documents')) {
        $query= "CREATE TABLE `glpi_plugin_documensobridge_documents` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `ticket_id` INT(11) NOT NULL DEFAULT 0,
                `documenso_id` INT(11) DEFAULT 0,
                `recipient_signer_id` INT(11) DEFAULT 0,
                `date_add` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

        $DB->doQuery($query);
    }

    // ====================================
    //    CREACIÓN DE CATEGORÍA REQUESTER
    // ====================================
    $category_name_req= "documenso_plugin_requester";
    $query_check_req = "SELECT id FROM `glpi_documentcategories` WHERE name = '".$DB->escape($category_name_req)."'";
    $result_req = $DB->doQuery($query_check_req);

    if(!$DB->numrows($result_req)){
        $query_insert_req = "INSERT INTO `glpi_documentcategories` 
            (`name`, `comment`, `documentcategories_id`, `completename`, `level`, `ancestors_cache`, `sons_cache`, `date_creation`, `date_mod`)
            VALUES (
                '".$DB->escape($category_name_req)."',                                            -- name
                'Esta es la categoria por defecto para subir los documentos a Documenso y se enlaza con el usuario del Solicitante. NO se debe modificar nada de la categoria fuera de la configuracion del plugin.',     -- comment
                0,                                                                            -- documentcategories_id
                '".$DB->escape($category_name_req)."',                                        -- completename
                1,                                                                            -- level
                NULL,                                                                         -- ancestors_cache
                '{}',                                                                         -- sons_cache
                NOW(),                                                                        -- date_creation
                NOW()                                                                         -- date_mod
            )";

        $DB->doQuery($query_insert_req);
    }

    // ====================================
    //    CREACIÓN DE CATEGORÍA OBSERVER
    // ====================================
    $category_name_obs= "documenso_plugin_observer";
    $query_check_obs = "SELECT id FROM `glpi_documentcategories` WHERE name = '".$DB->escape($category_name_obs)."'";
    $result_obs = $DB->doQuery($query_check_obs);

    if(!$DB->numrows($result_obs)){
        $query_insert_obs = "INSERT INTO `glpi_documentcategories` 
            (`name`, `comment`, `documentcategories_id`, `completename`, `level`, `ancestors_cache`, `sons_cache`, `date_creation`, `date_mod`)
            VALUES (
                '".$DB->escape($category_name_obs)."',                                            -- name
                'Esta es la categoria por defecto para subir los documentos a Documenso y se enlaza con el usuario del Observador. NO se debe modificar nada de la categoria fuera de la configuracion del plugin.',     -- comment
                0,                                                                            -- documentcategories_id
                '".$DB->escape($category_name_obs)."',                                        -- completename
                1,                                                                            -- level
                NULL,                                                                         -- ancestors_cache
                '{}',                                                                         -- sons_cache
                NOW(),                                                                        -- date_creation
                NOW()                                                                         -- date_mod
            )";

        $DB->doQuery($query_insert_obs);
    }

    // ====================================
    //     CREACIÓN DE CONFIGURACIÓN
    // ====================================
    PluginDocumensoBridgeConfig::install();

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

    // ========================================
    //    ELIMINACIÓN DE CATEGORÍA REQUESTER
    // ========================================
    $category_comment_req= 'Esta es la categoria por defecto para subir los documentos a Documenso y se enlaza con el usuario del Solicitante. NO se debe modificar nada de la categoria fuera de la configuracion del plugin.';
    $query_check_req = "SELECT id FROM `glpi_documentcategories` WHERE comment = '".$DB->escape($category_comment_req)."'";
    $result_req = $DB->doQuery($query_check_req);
    
    if($DB->numrows($result_req)){
        $query_delete_req = "DELETE FROM `glpi_documentcategories` WHERE comment = '".$DB->escape($category_comment_req)."'";
        $DB->doQuery($query_delete_req);
    }


    // ========================================
    //    ELIMINACIÓN DE CATEGORÍA REQUESTER
    // ========================================
    $category_comment_obs= 'Esta es la categoria por defecto para subir los documentos a Documenso y se enlaza con el usuario del Observador. NO se debe modificar nada de la categoria fuera de la configuracion del plugin.';
    $query_check_obs = "SELECT id FROM `glpi_documentcategories` WHERE comment = '".$DB->escape($category_comment_obs)."'";
    $result_obs = $DB->doQuery($query_check_obs);
    
    if($DB->numrows($result_obs)){
        $query_delete_obs = "DELETE FROM `glpi_documentcategories` WHERE comment = '".$DB->escape($category_comment_obs)."'";
        $DB->doQuery($query_delete_obs);
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
    $query_config= "SELECT * FROM `glpi_plugin_documenso_bridgeconfigs` WHERE id = 1";
    $result_config= $DB->doQuery($query_config);
    $config_data = $DB->fetchAssoc($result_config);

    $observer= false;    

    $category_name_req= $config_data["category_name_requester"];
    $category_name_obs= $config_data["category_name_observer"];

    if($config_data["category_name_requester"]== ''){
        $category_name_req= "documenso_plugin_requester";
    }

    if($config_data["category_name_observer"]== ''){
        $category_name_req= "documenso_plugin_observer";
    }

    if ($document_item->fields['itemtype'] !== 'Ticket') {
        return;
    }

    $ticket_id = $document_item->fields['items_id'];

    $document = new Document();
    $document->getFromDB($document_item->fields['documents_id']);

    
    $query_category_id = "SELECT id FROM `glpi_documentcategories` WHERE name = '".$DB->escape($category_name_req)."'";
    $result = $DB->doQuery($query_category_id);
    $row = $DB->fetchAssoc($result);
    $category_id = $row['id'];
    
    // Verificar que sea la categoría exacta del plugin
    if($document->fields['documentcategories_id']!= $category_id){
        $new_query_category_id = "SELECT id FROM `glpi_documentcategories` WHERE name = '".$DB->escape($category_name_obs)."'";
        $new_result = $DB->doQuery($new_query_category_id);
        $row = $DB->fetchAssoc($new_result);
        $category_id = $row['id'];

        // No es un error, es simplemente una subida de documento con una categoría que no es del plugin
        if($document->fields['documentcategories_id']!= $category_id){
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
   
    PluginDocumensobridgeDocumensoAPI::sendToDocumenso($ticket, $file_path, $config_data, $observer);
}
