<?php

/**
 * -------------------------------------------------------------------------
 * DocumensoBridge plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of DocumensoBridge.
 *
 * DocumensoBridge is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * DocumensoBridge is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with DocumensoBridge. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @author    François Legastelois
 * @copyright Copyright (C) 2016-2022 by DocumensoBridge plugin team.
 * @license   AGPLv3 https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://github.com/pluginsGLPI/DocumensoBridge
 * -------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;

use function Safe\copy;
use function Safe\mkdir;

class PluginDocumensoBridgeConfig extends CommonDBTM
{
    public static $rightname = 'config';

    /**
     * Display name of itemtype
     *
     * @return string
     **/
    public static function getTypeName($nb = 0)
    {
        return __s('Documenso Bridge', 'documensobridge');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        switch ($item->getType()) {
            case "Config":
                return self::createTabEntry(self::getTypeName(), 0, $item::getType(), self::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        $config = new self();
        switch ($item->getType()) {
            case "Config":
                $config->showConfigForm();
        }

        return true;
    }

    public function showConfigForm()
    {
        $this->getFromDB(1);

        TemplateRenderer::getInstance()->display(
            '@documensobridge/config.html.twig',
            [
                'action'  => '/plugins/documensobridge/front/config.form.php',
                'item'    => $this,
            ],
        );

        return true;
    }   

    /**
     * Load configuration plugin in GLPi Session
     *
     * @return void
     */
    public static function loadInSession()
    {
        $config = new self();
        $config->getFromDB(1);
        unset($config->fields['id']);
        $_SESSION['plugins']['documensobridge']['config'] = $config->fields;
    }

    /**
     * Install all necessary tables for the plugin
     * @param string $category_name_requester No es null si la categoría ya existía antes de la instalación
     * @param string $category_name_observer No es null si la categoría ya existía antes de la instalación
     * @return boolean True if success
     */
    public static function install($category_name_requester, $category_name_observer)
    {
        global $DB;

        $category_name_requester= $category_name_requester ?? 'documenso_plugin_requester';
        $category_name_observer= $category_name_observer ?? 'documenso_plugin_observer';

        $default_charset= DBConnection::getDefaultCharset();
        $default_collation= DBConnection::getDefaultCollation();
        $default_key_sign= DBConnection::getDefaultPrimaryKeySignOption();

        $table = getTableForItemType(self::class);

        if (!$DB->tableExists($table)) {

            // Se crea la tabla de configuración
            $query = "CREATE TABLE IF NOT EXISTS `$table` (
                     `id` INT(11) {$default_key_sign} NOT NULL AUTO_INCREMENT,
                     `page_value` TINYINT NOT NULL DEFAULT 1,
                     `posX_value` FLOAT NOT NULL DEFAULT 67.4953,
                     `posY_value` FLOAT NOT NULL DEFAULT 64.6156,
                     `height_value` FLOAT NOT NULL DEFAULT 6.54827,
                     `width_value` FLOAT NOT NULL DEFAULT 23.7853,
                     `category_name_requester` VARCHAR(250) NOT NULL DEFAULT 'documenso_plugin_requester',
                     `category_name_observer` VARCHAR(250) NOT NULL DEFAULT 'documenso_plugin_observer',
                     `documenso_api_key` VARCHAR(250) DEFAULT NULL,
               PRIMARY KEY  (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
            $DB->doQuery($query);

            // Se inserta la instancia con ID=1
            $DB->insert($table, ['id' => 1, 
                'category_name_requester' => $category_name_requester, 
                'category_name_observer' => $category_name_observer
            ]);
        }

        return true;
    }

    /**
     * Uninstall previously installed tables of the plugin
     *
     * @return boolean True if success
     */
    public static function uninstall()
    {
        /** @var DBmysql $DB */
        global $DB;

        $table = 'glpi_plugin_documenso_bridgeconfigs';

        $query = 'DROP TABLE IF EXISTS  `' . $table . '`';
        $DB->doQuery($query);

        return true;
    }

    /**
     * Llama a la función de actualización del nombre de la categoría del documento concreto
     *
     * @return void
     */
    function post_updateItem($history = 1) {
        $this->plugin_documensobridge_config_update_hook();
    }

    /**
     * Actualiza el nombre de la categoría modificada en la página de configuración
     *
     * @return bool True if success
     */
    public function plugin_documensobridge_config_update_hook() {

        $table = "glpi_documentcategories";

        // =======================================
        //       ACTUALIZACIÓN DEL REQUESTER
        // =======================================
        if (isset($this->input['category_name_requester'])) {
            
            $newCategory = $this->input['category_name_requester'] ?? 'documenso_plugin_requester';
            
            if($newCategory === ''){
                $newCategory= 'documenso_plugin_requester';
            }

            $comment_req= 'Esta es la categoria por defecto para subir los documentos a Documenso y se enlaza con el usuario del Solicitante. NO se debe modificar nada de la categoria fuera de la configuracion del plugin.';

            global $DB;

            // Se busca la categoría del plugin por descripción
            $query_select_req = "SELECT id, name FROM `$table`
                            WHERE comment= '".$comment_req."'";
            $result_req= $DB->doQuery($query_select_req);

            // Si se encuentra un único resultado
            if ($DB->numrows($result_req) === 1) {
                $row = $DB->fetchAssoc($result_req);
                $category_id = $row['id'];

                $query_update = "UPDATE `$table` 
                        SET name = '".$DB->escape($newCategory)."'
                        WHERE id = '".$category_id."'";
                $DB->doQuery($query_update);

            } 

            // Si no se encuentra ningún resultado
            else if ($DB->numrows($result_req) === 0){
                // Se vuelve a crear una nueva categoría ya que seguramente se habrá eliminado.
                $category_name= $newCategory;
                $query_check = "SELECT id FROM `glpi_documentcategories` WHERE comment = '".$comment_req."'";
                $result_insert = $DB->doQuery($query_check);

                if(!$DB->numrows($result_insert)){
                    $query_insert = "INSERT INTO `glpi_documentcategories` 
                        (`name`, `comment`, `documentcategories_id`, `completename`, `level`, `ancestors_cache`, `sons_cache`, `date_creation`, `date_mod`)
                        VALUES (
                            '".$DB->escape($category_name)."',                                            -- name
                            '".$comment_req."',                                                           -- comment
                            0,                                                                            -- documentcategories_id
                            'documenso_plugin_requester',                                                 -- completename
                            1,                                                                            -- level
                            NULL,                                                                         -- ancestors_cache
                            '{}',                                                                         -- sons_cache
                            NOW(),                                                                        -- date_creation
                            NOW()                                                                         -- date_mod
                        )";

                    $DB->doQuery($query_insert);
                }
            } else{
                // Sale si se ha creado otra categoría con la misma descripción.
                Session::addMessageAfterRedirect(
                    __('Hay dos categorías con la misma descripción que la categoría del Solicitante. Intenta eliminar las categorías con la misma descripción hasta que solo quede una.'),
                    false,
                    ERROR
                );
            }
        }

        // =======================================
        //      ACTUALIZACIÓN DEL OBSERVER
        // =======================================
        if(isset($this->input['category_name_observer'])){
            $newCategory = $this->input['category_name_observer'] ?? 'documenso_plugin_observer';
            
            if($newCategory === ''){
                $newCategory= 'documenso_plugin_observer';
            }

            $comment_obs= 'Esta es la categoria por defecto para subir los documentos a Documenso y se enlaza con el usuario del Observador. NO se debe modificar nada de la categoria fuera de la configuracion del plugin.';

            global $DB;

            // Se busca la categoría del plugin por descripción
            $query_select_obs = "SELECT id, name FROM `$table`
                            WHERE comment= '".$comment_obs."'";
            $result_obs= $DB->doQuery($query_select_obs);

            // Si se encuentra un único resultado
            if ($DB->numrows($result_obs) === 1) {
                $row = $DB->fetchAssoc($result_obs);
                $category_id = $row['id'];

                $query_update = "UPDATE `$table` 
                        SET name = '".$DB->escape($newCategory)."'
                        WHERE id = '".$category_id."'";
                $DB->doQuery($query_update);

            }

            // Si no se encuentra ningún resultado
            else if ($DB->numrows($result_obs) === 0){
                // Se vuelve a crear una nueva categoría ya que seguramente se habrá eliminado.
                $category_name= $newCategory;
                $query_check = "SELECT id FROM `glpi_documentcategories` WHERE comment = '".$comment_obs."'";
                $result_insert = $DB->doQuery($query_check);

                if(!$DB->numrows($result_insert)){
                    $query_insert = "INSERT INTO `glpi_documentcategories` 
                        (`name`, `comment`, `documentcategories_id`, `completename`, `level`, `ancestors_cache`, `sons_cache`, `date_creation`, `date_mod`)
                        VALUES (
                            '".$DB->escape($category_name)."',                                            -- name
                            '".$comment_obs."',                                                           -- comment
                            0,                                                                            -- documentcategories_id
                            'documenso_plugin_observer',                                                  -- completename
                            1,                                                                            -- level
                            NULL,                                                                         -- ancestors_cache
                            '{}',                                                                         -- sons_cache
                            NOW(),                                                                        -- date_creation
                            NOW()                                                                         -- date_mod
                        )";

                    $DB->doQuery($query_insert);
                }
            } else{
                // Sale si se ha creado otra categoría con la misma descripción.
                Session::addMessageAfterRedirect(
                    __('Hay dos categorías con la misma descripción que la categoría del Observador. Intenta eliminar las categorías con la misma descripción hasta que solo quede una.'),
                    false,
                    ERROR
                );
                return false;
            }

            return true;

        }

        // Se actualizó la configuración pero no se modificaron los campos de observer ni requester.
        return false;
    }


    public static function getIcon()
    {
        // Generic icon that is not visible, but still takes up space to allow proper alignment in lists
        return "ti ti-clipboard-list";
    }

}
