<?php

use Glpi\Plugin\Hooks;

define('PLUGIN_DOCUMENSOBRIDGE_VERSION', '0.1.0');
// Minimal GLPI version, inclusive
define('PLUGIN_DOCUMENSOBRIDGE_MIN_GLPI', '11.0.0');
// Maximum GLPI version, exclusive
define('PLUGIN_DOCUMENSOBRIDGE_MAX_GLPI', '11.0.99');


function plugin_init_documensobridge() {
   global $PLUGIN_HOOKS;

   $PLUGIN_HOOKS['csrf_compliant']['documensobridge'] = true;
   
   // IMPORTANTE: cargar siempre hook.php
   include_once(__DIR__ . "/hook.php");

   // Registro instalación/desinstalación
   $PLUGIN_HOOKS['install_process']['documensobridge']   = 'plugin_documensobridge_install';
   $PLUGIN_HOOKS['uninstall_process']['documensobridge'] = 'plugin_documensobridge_uninstall';
   
   $plugin = new Plugin();

   if ($plugin->isInstalled('documensobridge') && $plugin->isActive('documensobridge')) {
      PluginDocumensoBridgeConfig::loadInSession();
      
      // Cuando recibe item_add llama a la función de documenso
      $PLUGIN_HOOKS['item_add']['documensobridge'] = [
         'Document_Item' => 'plugin_documensobridge_document_add',
      ];

      if(Session::haveRight('config', UPDATE)) {
         $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['documensobridge'] = '../../front/config.form.php?forcetab=PluginDocumensoBridgeConfig$1';
         Plugin::registerClass(PluginDocumensoBridgeConfig::class, ['addtabon' => [Config::class]]);  
      }
   }
}


function plugin_version_documensobridge() {
   return [
      'name'           => 'Documenso Bridge',
      'version'        => PLUGIN_DOCUMENSOBRIDGE_VERSION,
      'author'         => 'Carlos de la Torre Frías',
      'license'        => 'GPLv2+',
      'requirements'   => [
         'glpi' => [
            'min' => PLUGIN_DOCUMENSOBRIDGE_MIN_GLPI,
            'max' => PLUGIN_DOCUMENSOBRIDGE_MAX_GLPI,
         ]
      ]
   ];
}