<?php

/**
 * -------------------------------------------------------------------------
 * Example plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Example.
 *
 * Example is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Example is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Example. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2006-2022 by Example plugin team.
 * @license   GPLv2 https://www.gnu.org/licenses/gpl-2.0.html
 * @link      https://github.com/pluginsGLPI/example
 * -------------------------------------------------------------------------
 */

// ----------------------------------------------------------------------
// Original Author of file:
// Purpose of file:
// ----------------------------------------------------------------------

// Non menu entry case
//header("Location:../../central.php");

// Entry menu case
include(__DIR__ . '/../../../inc/includes.php');

// Comprobación de permisos
Session::checkRight('config', UPDATE);

// Carga el header con los siguientes datos: plugins/documensobridge/config/
Html::header(
    PluginUseditemsexportConfig::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'plugins',
    'documensobridge',
    'config',
);

// Carga la clase
$PluginDocumensoBridgeConfig = new PluginDocumensoBridgeConfig();

// Comprueba si hay alguna actualización del contenido.
if (isset($_POST['update'])) {
    $PluginDocumensoBridgeConfig->check($_POST['id'], UPDATE);
    $PluginDocumensoBridgeConfig->update($_POST);
    Html::back();
}

// Muestra el formulario (CommonDBTM)
$PluginUseditemsexportConfig->showForm($_GET['id']);

// Renderizado de footer.
Html::footer();
