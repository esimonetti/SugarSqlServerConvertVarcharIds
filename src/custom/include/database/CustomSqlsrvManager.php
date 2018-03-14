<?php

// Enrico Simonetti
// enricosimonetti.com
//
// 2018-03-14 on 7.9.3.0
// override Sugar ids to be nvarchar(36) instead of varchar(36)

// $sugar_config['dbconfig']['db_manager'] = 'CustomSqlsrvManager'; 

class CustomSqlsrvManager extends SqlsrvManager
{
    public function __construct()
    {
        $this->type_map['id'] = 'nvarchar(36)';
        return parent::__construct();
    }
}
