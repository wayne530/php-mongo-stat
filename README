This class will take a Mongo connection object (see http://php.net/mongo), determine the
type of connection (single server, replica set, sharded) and recursively connect to each
server to determine some basic details about the server (version, hostname, port, memory
utilization, etc).

<pre>
&lt;?php

require_once('mongo/Stat.class.php');

$mongo = new Mongo('mongodb://127.0.0.1:27017', array('replicaSet' =&gt; false));
$info = Mongo_Stat::getConnectionDetails($mongo);

var_dump($info);
</pre>

