<?php
// app/command/CacheData.php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Cache;

class CacheData extends Command
{
    protected function configure()
    {
        $this->setName('cache:data')->setDescription('Cache data table to Redis');
    }

    protected function execute(Input $input, Output $output)
    {
        // Increase execution time and memory limit
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '9016M');
    
        $redis = Cache::store('redis')->handler();
    
        // Retrieve unique types from the 'data' table
        $types = Db::name('data')->distinct(true)->column('type');
    
        // Cache data to Redis
        foreach ($types as $type) {
            if($type=='155'){
                $existingAccountsKey = "existing_accounts:$type";
                $redis->del($existingAccountsKey);
        
                // Get accounts for the current type
                $accounts = Db::name('data')->where('type', $type)->column('account');
        
                // Use hMset to set multiple hash fields at once
                $redis->hMset($existingAccountsKey, array_fill_keys($accounts, 1));
                
            }
        }
    
        $output->writeln('Data cached to Redis successfully!');
    }



}
