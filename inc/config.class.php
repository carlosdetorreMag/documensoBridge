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
     *
     * @return boolean True if success
     */
    public static function install()
    {
        /** @var DBmysql $DB */
        global $DB;

        $default_charset= DBConnection::getDefaultCharset();
        $default_collation= DBConnection::getDefaultCollation();
        $default_key_sign= DBConnection::getDefaultPrimaryKeySignOption();

        $table = getTableForItemType(self::class);

        if (!$DB->tableExists($table)) {

            $query = "CREATE TABLE IF NOT EXISTS `$table` (
                     `id` INT(11) {$default_key_sign} NOT NULL AUTO_INCREMENT,
                     `page_value` TINYINT NOT NULL DEFAULT 1,
                     `posX_value` FLOAT NOT NULL DEFAULT 0.5,
                     `posY_value` FLOAT NOT NULL DEFAULT 0.5,
                     `height_value` FLOAT NOT NULL DEFAULT 5,
                     `width_value` FLOAT NOT NULL DEFAULT 5,
                     `category_name` VARCHAR(250) NOT NULL DEFAULT 'documenso_plugin_header',
               PRIMARY KEY  (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
            $DB->doQuery($query);

            $DB->insert($table, ['id' => 1]);
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
     * Call the post update function
     *
     * @return void
     */
    function post_updateItem($history = 1) {
        $this->plugin_documensobridge_config_update_hook();
    }

    /**
     * Update the docuement category name when its done by the plugin configuration
     *
     * @return bool True if success
     */
    public function plugin_documensobridge_config_update_hook() {
        // Verifica si se actualizó category_name
        if (isset($this->input['category_name'])) {
            $newCategory = $this->input['category_name'] ?? 'documenso_plugin_header';
            if($newCategory == ''){
                $newCategory= 'documenso_plugin_header';
            }
            $comment= 'Esta es la categoria por defecto para subir los documentos a Documenso. Se recomienda no modificar la categoria fuera de la configuracion del plugin.';

            global $DB;

            $table = "glpi_documentcategories";
            $query_select = "SELECT id, name FROM `$table`
                            WHERE comment= '".$comment."'";
            $result= $DB->doQuery($query_select);

            if ($DB->numrows($result) > 0) {
                $row = $DB->fetchAssoc($result);
                $category_id = $row['id'];

                $query_update = "UPDATE `$table` 
                        SET name = '".$DB->escape($newCategory)."'
                        WHERE id = '".$category_id."'";
                $DB->doQuery($query_update);

            } else if ($DB->numrows($result) == 0){
                // Si no existe ninguna línea con la categoría, se vuelve a crear una nueva
                $category_name= "documenso_plugin_header";
                $query_check = "SELECT id FROM `glpi_documentcategories` WHERE comment = '".$comment."'";
                $result_insert = $DB->doQuery($query_check);

                if(!$DB->numrows($result_insert)){
                    $query_insert = "INSERT INTO `glpi_documentcategories` 
                        (`name`, `comment`, `documentcategories_id`, `completename`, `level`, `ancestors_cache`, `sons_cache`, `date_creation`, `date_mod`)
                        VALUES (
                            '".$DB->escape($category_name)."',                                            -- name
                            '".$comment."',                                                               -- comment
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
            } else{
                // Sale si se ha creado otra categoría con la misma descripción.
                return false;
            }

            return true;
        }

        // No se actualizó la configuración
        return false;
    }


    public static function getIcon()
    {
        // Generic icon that is not visible, but still takes up space to allow proper alignment in lists
        return "ti ti-clipboard-list";
    }

}
