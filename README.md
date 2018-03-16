# SugarSqlServerConvertVarcharIds

* Copy all files within `src/custom/*` into instance's `custom/`
* Restart IIS and Run Quick Repair and Rebuild
* Add on `config_override.php` `$sugar_config['dbconfig']['db_manager'] = 'CustomSqlsrvManager';`
* Restart IIS if required to overcome caching
* Run via command line the conversion script under utilities (run.bat right click pressing shift and selecting the correct user)
