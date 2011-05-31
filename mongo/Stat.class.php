<?php

class Mongo_Stat {

    /**
     * get connection details for the provided mongodb connection object
     *
     * @static
     * @param Mongo $mongo  connection object
     * @param bool $recurse  whether to recursively load server info; default true
     *
     * @return array  connection details
     */
    public static function getConnectionDetails(Mongo $mongo, $recurse = true) {
        $db = $mongo->selectDB('admin');
        $serverStatus = $db->command(array('serverStatus' => true));
        $details = array(
            'name' => $serverStatus['host'],
            'type' => $serverStatus['process'],
            'version' => $serverStatus['version'],
            'uptimeSecs' => $serverStatus['uptime'],
            'memResident' => isset($serverStatus['mem']) ? $serverStatus['mem']['resident'] * 1024 * 1024 : null,
            'memVirtual' => isset($serverStatus['mem']) ? $serverStatus['mem']['virtual'] * 1024 * 1024 : null,
        );
        if (! $recurse) {
            return $details;
        }
        // determine type of server we're talking to and recursively obtain details if necessary
        $details['isMongos'] = $serverStatus['process'] === 'mongos';
        $details['isReplSet'] = isset($serverStatus['repl']) && is_array($serverStatus['repl']) && isset($serverStatus['repl']['setName']);
        if ($details['isMongos']) {
            $shardingDetails = self::getShardingConnectionDetails($db);
            $details = array_merge($details, $shardingDetails);
        } else if ($details['isReplSet']) {
            $setName = $serverStatus['repl']['setName'];
            $hosts = $serverStatus['repl']['hosts'];
            $arbiters = $serverStatus['repl']['arbiters'];
            $details['replSetName'] = $setName;
            $details['hosts'] = array();
            foreach ($hosts as $host) {
                $hostMongo = new Mongo($host, array('replicaSet' => false));
                $hostDetails = self::getConnectionDetails($hostMongo, false);
                $hostMongo->close();
                $hostDetails['isArbiter'] = false;
                $details['hosts'][] = $hostDetails;
            }
            foreach ($arbiters as $host) {
                $hostMongo = new Mongo($host, array('replicaSet' => false));
                $hostDetails = self::getConnectionDetails($hostMongo, false);
                $hostMongo->close();
                $hostDetails['isArbiter'] = true;
                $details['hosts'][] = $hostDetails;
            }
        }
        // get currentOp
        $details['currentOp'] = self::getCurrentOp($db);
        return $details;
    }

    /**
     * get sorted inprog ops by time running in descending order
     *
     * @static
     * @param MongoDB $db  mongodb db object
     *
     * @return array
     */
    public static function getCurrentOp(MongoDB $db) {
        $collection = $db->selectCollection('$cmd.sys.inprog');
        $currentOp = $collection->findOne();
        return MongoAnalytics::sort($currentOp['inprog'], array('secs_running:int' => -1));
    }

    /**
     * return sharding details from the provided database object
     *
     * @static
     * @param MongoDB $db  database object
     *
     * @return array  sharding details
     */
    public static function getShardingConnectionDetails(MongoDB $db) {
        $details = array(
            'configServers' => array(),
            'shards' => array(),
        );
        // get config servers
        $netStat = $db->command(array('netstat' => true));
        if (isset($netStat['configserver'])) {
            foreach (explode(',', $netStat['configserver']) as $host) {
                $configMongo = new Mongo($host, array('replicaSet' => false));
                $details['configServers'][] = self::getConnectionDetails($configMongo, false);
            }
        }
        // get shard details
        $listShards = $db->command(array('listShards' => true));
        if (isset($listShards['shards'])) {
            foreach ($listShards['shards'] as $shard) {
                $name = $shard['_id'];
                $host = $shard['host'];
                $replicaSet = false;
                if (preg_match('/^\w+\/(.+)/', $host, $matches)) {
                    $host = $matches[1];
                    $replicaSet = true;
                }
                $shardMongo = new Mongo($host, array('replicaSet' => $replicaSet));
                $shardDetails = self::getConnectionDetails($shardMongo);
                $shardMongo->close();
                $shardDetails['shardName'] = $name;
                $details['shards'][] = $shardDetails;
            }
        }
        return $details;
    }

}
