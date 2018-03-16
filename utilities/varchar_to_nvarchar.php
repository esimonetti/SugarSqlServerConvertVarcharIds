<?php

// Enrico Simonetti
// enricosimonetti.com
//
// 2018-03-16 on Sugar 7.9.3.0

function usage($error = '') {
    if (!empty($error)) print(PHP_EOL . 'Error: ' . $error . PHP_EOL);
    print('  php ' . __FILE__ . ' --instance /full/path' . PHP_EOL);
    exit(1);
}

// only allow CLI
$sapi_type = php_sapi_name();
if (substr($sapi_type, 0, 3) != 'cli') {
    die(__FILE__ . ' is CLI only.');
}

// get command line params
$o = getopt('', array('instance:'));
if (!$o) usage();

// find directory
if (!empty($o['instance']) && is_dir($o['instance'])) {
    print('Debug: Entering directory ' . $o['instance'] . PHP_EOL);
    chdir($o['instance']);
} else {
    chdir(dirname(__FILE__));
}

if (!file_exists('config.php') || !file_exists('sugar_version.php')) {
    usage('The provided directory is not a Sugar system');
}

// sugar basic setup
define('sugarEntry', true);
require_once('include/entryPoint.php');

if (extension_loaded('xdebug')) {
    echo 'Xdebug is enabled on this system. It is highly recommended to disable Xdebug on PHP CLI before running this script. Xdebug will cause unwanted slowness.'.PHP_EOL;
}

// temporarily stop xdebug, xhprof and tideways if enabled
if (function_exists('xdebug_disable')) {
    xdebug_disable();
}

if(function_exists('xhprof_disable')) {
    xhprof_disable();
    xhprof_sample_disable();
}

if (function_exists('tideways_disable')) {
    tideways_disable();
}

if (empty($current_language)) {
    $current_language = $sugar_config['default_language'];
}

$app_list_strings = return_app_list_strings_language($current_language);
$app_strings = return_application_language($current_language);
$mod_strings = return_module_language($current_language, 'Administration');

global $current_user, $dictionary;
$current_user = BeanFactory::getBean('Users');
$current_user->getSystemUser();

$start_time = microtime(true);

echo 'Converting database id fields...' . PHP_EOL;

include('modules/TableDictionary.php');
if (file_exists('custom/application/Ext/TableDictionary/tabledictionary.ext.php')) {
    include('custom/application/Ext/TableDictionary/tabledictionary.ext.php');
}

// require two files that otherwise do not seem to load
require_once('modules/Administration/vardefs.php');
require_once('modules/Trackers/vardefs.php');

$db = DBManagerFactory::getInstance();
//$db->setOption('skip_index_rebuild', true);

$tables_orig = $db->getTablesArray();
// get table names as keys as well
$tables = array_combine($tables_orig, $tables_orig);

$transformations = array();

// loading audit bean template for later
require('metadata/audit_templateMetaData.php');
$audit_fields = $dictionary['audit']['fields'];
$audit_transformations = array();
foreach ($audit_fields as $field) {
    if ($field['type'] == 'id' || $field['dbType'] == 'id') {
        $audit_transformations[$field['name']] = $field;
    }
}

$full_module_list = array_merge($beanList, $app_list_strings['moduleList']);
foreach ($full_module_list as $module => $value) {
    $bean = BeanFactory::newBean($module);
    if (!empty($dictionary[$bean->object_name]) && !empty($dictionary[$bean->object_name]['table'])) {
        if (!empty($tables[$dictionary[$bean->object_name]['table']])) {
            //echo 'Processing ' . $dictionary[$bean->object_name]['table'] . PHP_EOL;
            foreach ($dictionary[$bean->object_name]['fields'] as $field) {
                if ($field['type'] == 'id' || $field['dbType'] == 'id') {
                    $transformations[$dictionary[$bean->object_name]['table']][$field['name']] = $field;
                }
            }
            unset($tables[$dictionary[$bean->object_name]['table']]);

            // looking at custom
            if ($bean->hasCustomFields()) {
                $custom_table = $bean->get_custom_table_name();
        
                foreach ($bean->field_defs as $field) {
                    if ($field['source'] == 'custom_fields') {
                        if($field['type'] == 'id' || $field['dbType'] == 'id') {
                            $transformations[$custom_table][$field['name']] = $field;
                        }
                    } 
                }

                // add the id_c manually as a modified copy of the id
                $id_c = $bean->field_defs['id'];
                $id_c['name'] = 'id_c';
                $id_c['isnull'] = false;
                $transformations[$custom_table]['id_c'] = $id_c;

                unset($tables[$custom_table]);
            }

            // looking at audit
            if ($bean->is_AuditEnabled()) {
                $audit_table = $bean->get_audit_table_name();
                $transformations[$audit_table] = $audit_transformations;

                unset($tables[$audit_table]);
            }
        }
    }
}

// catch all the other tables like relationships etc
foreach ($dictionary as $d_id => $d) {
    if (!empty($d['table'])) {
        if (!empty($tables[$d['table']])) {
            //echo 'Processing ' . $d['table'] . PHP_EOL;
            foreach ($d['fields'] as $field) {
                if ($field['type'] == 'id' || $field['dbType'] == 'id') {
                    $transformations[$d['table']][$field['name']] = $field;
                } 
            } 
            unset($tables[$d['table']]);
        }
    }
}

if (!empty($tables)) {
    echo PHP_EOL . 'PROBLEM, the following tables could not be processed, the script might need modifications. Aborting before completing any changes.' . PHP_EOL;
    print_r($tables);
    die();
}

$template = array(
    'type' => 'nvarchar',
    'len' => '36',
);

// check and perform transformation if required
$sql = '';
if (!empty($transformations)) {
    foreach ($transformations as $table_name => $table) {
        // retrieve current db definition for the table
        $columns = $db->get_columns($table_name);
        foreach ($table as $field_name => $field) {
            // exec only if field exists...
            if (!empty($columns[$field_name])) {
                if ($columns[$field_name]['type'] != $template['type'] || $columns[$field_name]['len'] != $template['len']) {
                    // need to perform change
                    $current_field = $template;
                    $current_field['name'] = $field_name;
                    if (isset($field['required'])) {
                        $current_field['required'] = $field['required'];
                    }
                    if (isset($field['default'])) {
                        $current_field['default'] = $field['default'];
                    }
                    if (isset($field['isnull'])) {
                        $current_field['isnull'] = $field['isnull'];
                    }

                    echo $db->alterColumnSQL($table_name, $current_field, false) . PHP_EOL; 
                    // execute the actual sql
                    $db->alterColumn($table_name, $current_field, false);
                }
            }
        }
    }
}

//print_r($transformations);

print('Completed in ' . (int)(microtime(true) - $start_time) . ' seconds.' . PHP_EOL);
