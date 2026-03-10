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

    // Verificar si la tabla existe
    if (!$DB->tableExists('glpi_plugin_documensobridge_documents')) {
        $query= "CREATE TABLE `glpi_plugin_documensobridge_documents` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `tickets_id` INT(11) NOT NULL DEFAULT 0,
                `documenso_id` VARCHAR(255) DEFAULT '',
                `date_add` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

        $DB->doQuery($query);
    }

    $category_name= "documenso_plugin_header";
    $query_check = "SELECT id FROM `glpi_documentcategories` WHERE name = '".$DB->escape($category_name)."'";
    $result = $DB->doQuery($query_check);

    if(!$DB->numrows($result)){
        $query_insert = "INSERT INTO `glpi_documentcategories` 
            (`name`, `comment`, `documentcategories_id`, `completename`, `level`, `ancestors_cache`, `sons_cache`, `date_creation`, `date_mod`)
            VALUES (
                '".$DB->escape($category_name)."',                                            -- name
                'Esta es la categoria por defecto para subir los documentos a Documenso. Se recomienda no modificar la categoria fuera de la configuracion del plugin.',     -- comment
                0,                                                                            -- documentcategories_id
                '".$DB->escape($category_name)."',                                            -- completename
                1,                                                                            -- level
                NULL,                                                                         -- ancestors_cache
                '{}',                                                                         -- sons_cache
                NOW(),                                                                        -- date_creation
                NOW()                                                                         -- date_mod
            )";

        $DB->doQuery($query_insert);
    }

    // Configuración del plugin
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

    if ($DB->tableExists('glpi_plugin_documensobridge_documents')) {
        $query = 'DROP TABLE `glpi_plugin_documensobridge_documents`';
        $DB->doQuery($query);
    }

    $category_name= "documenso_plugin_header";
    $query_check = "SELECT id FROM `glpi_documentcategories` WHERE name = '".$DB->escape($category_name)."'";
    $result = $DB->doQuery($query_check);
    
    if($DB->numrows($result)){
        $query_delete = "DELETE FROM `glpi_documentcategories` WHERE name = '".$DB->escape($category_name)."'";
        $DB->doQuery($query_delete);
    }

    // Configuración del plugin
    PluginDocumensoBridgeConfig::uninstall();

    return true;
}

function plugin_documensobridge_document_add($document_item) {
    global $DB;
    $category_name= "documenso_plugin_header";

    if ($document_item->fields['itemtype'] !== 'Ticket') {
        return;
    }

    $ticket_id = $document_item->fields['items_id'];

    $document = new Document();
    $document->getFromDB($document_item->fields['documents_id']);

    // Verificar que sea PDF
    if ($document->fields['mime'] !== 'application/pdf') {
        return;
    }

    $query_category_id = "SELECT id FROM `glpi_documentcategories` WHERE name = '".$DB->escape($category_name)."'";
    $result = $DB->doQuery($query_category_id);
    $row = $DB->fetchAssoc($result);
    $category_id = $row['id'];

    // Verificar que sea la categoría exacta del plugin
    if($document->fields['documentcategories_id']!= $category_id){
        return;
    }

    $file_path = GLPI_DOC_DIR . "/" . $document->fields['filepath'];

    // Cargar ticket
    $ticket = new Ticket();
    $ticket->getFromDB($ticket_id);

    // Llamar API
    include_once(__DIR__ . "/inc/documensoapi.class.php");
   
    PluginDocumensobridgeDocumensoAPI::sendToDocumenso($ticket, $file_path);
}
