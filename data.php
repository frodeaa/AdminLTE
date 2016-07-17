<?php
$log = array();
$ipv6 = file_exists("/etc/pihole/.useIPv6");
$hosts = file_exists("/etc/hosts") ? file("/etc/hosts") : array();

/*******   Public Members ********/
function getSummaryData()
{
    global $ipv6;
    $log = readInLog();
    $domains_being_blocked = gravityCount() / ($ipv6 ? 2 : 1);

    $dns_queries_today = count(getDnsQueries($log));

    $ads_blocked_today = count(getBlockedQueries($log));

    $ads_percentage_today = $dns_queries_today > 0 ? ($ads_blocked_today / $dns_queries_today * 100) : 0;

    return array(
        'domains_being_blocked' => $domains_being_blocked,
        'dns_queries_today' => $dns_queries_today,
        'ads_blocked_today' => $ads_blocked_today,
        'ads_percentage_today' => $ads_percentage_today,
    );
}

function getOverTimeData()
{
    $log = readInLog();
    $dns_queries = getDnsQueries($log);
    $ads_blocked = getBlockedQueries($log);

    $domains_over_time = overTime($dns_queries);
    $ads_over_time = overTime($ads_blocked);
    alignTimeArrays($ads_over_time, $domains_over_time);
    return Array(
        'domains_over_time' => $domains_over_time,
        'ads_over_time' => $ads_over_time,
    );
}

function getTopItems()
{
    $log = readInLog();
    $dns_queries = getDnsQueries($log);
    $ads_blocked = getBlockedQueries($log);

    $topAds = topItems($ads_blocked);
    $topQueries = topItems($dns_queries, $topAds);

    return Array(
        'top_queries' => $topQueries,
        'top_ads' => $topAds,
    );
}

function getRecentItems($qty)
{
    $log = readInLog();
    $dns_queries = getDnsQueries($log);
    return Array(
        'recent_queries' => getRecent($dns_queries, $qty)
    );
}

function getIpvType()
{
    $log = readInLog();
    $dns_queries = getDnsQueries($log);
    $queryTypes = array();

    foreach ($dns_queries as $query) {
        $info = trim(explode(": ", $query)[1]);
        $queryType = explode(" ", $info)[0];
        if (isset($queryTypes[$queryType])) {
            $queryTypes[$queryType]++;
        } else {
            $queryTypes[$queryType] = 1;
        }
    }

    return $queryTypes;
}

function getForwardDestinations()
{
    $log = readInLog();
    $forwards = getForwards($log);
    $destinations = array();
    foreach ($forwards as $forward) {
        $exploded = explode(" ", trim($forward));
        $dest = $exploded[count($exploded) - 1];
        if (isset($destinations[$dest])) {
            $destinations[$dest]++;
        } else {
            $destinations[$dest] = 0;
        }
    }

    return $destinations;

}

function getQuerySources()
{
    $log = readInLog();
    $dns_queries = getDnsQueries($log);
    $sources = array();
    foreach ($dns_queries as $query) {
        $exploded = explode(" ", $query);
        $ip = hasHostName(trim($exploded[count($exploded) - 1]));
        if (isset($sources[$ip])) {
            $sources[$ip]++;
        } else {
            $sources[$ip] = 1;
        }
    }
    arsort($sources);
    $sources = array_slice($sources, 0, 10);
    return Array(
        'top_sources' => $sources
    );
}

function getAllQueries()
{
    $allQueries = array("data" => array());
    $log = readInLog();
    $dns_queries = getDnsQueriesAll($log);

    $status = false;
    foreach ($dns_queries as $query) {
        $time = date_create(substr($query, 0, 16));
        $exploded = explode(" ", trim($query));
        $tmp = $exploded[count($exploded) - 4];
        if (substr($tmp, 0, 5) == "query") {
            $type = substr($exploded[count($exploded) - 4], 6, -1);
            $domain = $exploded[count($exploded) - 3];
            $client = $exploded[count($exploded) - 1];
            $status = "";
        } elseif (substr($tmp, 0, 9) == "forwarded") {
            $status = "OK";
        } elseif (substr($tmp, strlen($tmp) - 12, 12) == "gravity.list" && $exploded[count($exploded) - 5] != "read") {
            $status = "Pi-holed";
        }

        if ($status != "") {
            array_push($allQueries['data'], array(
                $time->format('Y-m-d\TH:i:s'),
                $type,
                $domain,
                hasHostName($client),
                $status,
            ));
        }


    }
    return $allQueries;
}

function findBlockedDomain($domain)
{
    $exec = 'for list in /etc/pihole/list.*;do echo $list;grep \'' . $domain . '\' $list;done';
    exec($exec, $results);
    $lists = array();
    $index = 0;
    foreach ($results as $line) {
        if (preg_match('/\/etc\/pihole/', $line)) {
            $list = array();
            $list['list'] = basename($line);
            $list['found'] = false;
            $lists[] = $list;
            $index++;
        } else {
            $lists[$index - 1]['found'] = true;
        }

    }
    $found = array();
    foreach ($lists as $list) {
        if($list['found'] == true){
            $found[] = $list;
        }
    }

    return $found;
}

/******** Private Members ********/
function gravityCount()
{
    //returns count of domains in blocklist.
    $gravity = "/etc/pihole/gravity.list";
    $swallowed = 0;
    $NGC4889 = fopen($gravity, "r");
    while ($stars = fread($NGC4889, 1024000)) {
        $swallowed += substr_count($stars, "\n");
    }
    fclose($NGC4889);

    return $swallowed;

}

function readInLog()
{
    global $log;
    return count($log) > 1 ? $log :
        file("/var/log/pihole.log");
}

function getDnsQueries($log)
{
    return array_filter($log, "findQueries");
}

function getDnsQueriesAll($log)
{
    return array_filter($log, "findQueriesAll");
}

function getBlockedQueries($log)
{
    return array_filter($log, "findAds");
}

function getForwards($log)
{
    return array_filter($log, "findForwards");
}


function topItems($queries, $exclude = array(), $qty = 10)
{
    $splitQueries = array();
    foreach ($queries as $query) {
        $exploded = explode(" ", $query);
        $domain = trim($exploded[count($exploded) - 3]);
        if (!isset($exclude[$domain])) {
            if (isset($splitQueries[$domain])) {
                $splitQueries[$domain]++;
            } else {
                $splitQueries[$domain] = 1;
            }
        }
    }
    arsort($splitQueries);
    return array_slice($splitQueries, 0, $qty);
}

function overTime($entries)
{
    $byTime = array();
    foreach ($entries as $entry) {
        $time = date_create(substr($entry, 0, 16));
        $hour = $time->format('G');

        if (isset($byTime[$hour])) {
            $byTime[$hour]++;
        } else {
            $byTime[$hour] = 1;
        }
    }
    return $byTime;
}

function alignTimeArrays(&$times1, &$times2)
{
    $max = max(array(max(array_keys($times1)), max(array_keys($times2))));
    $min = min(array(min(array_keys($times1)), min(array_keys($times2))));

    for ($i = $min; $i <= $max; $i++) {
        if (!isset($times2[$i])) {
            $times2[$i] = 0;
        }
        if (!isset($times1[$i])) {
            $times1[$i] = 0;
        }
    }

    ksort($times1);
    ksort($times2);
}

function getRecent($queries, $qty)
{
    $recent = array();
    foreach (array_slice($queries, -$qty) as $query) {
        $queryArray = array();
        $exploded = explode(" ", $query);
        $time = date_create(substr($query, 0, 16));
        $queryArray['time'] = $time->format('h:i:s a');
        $queryArray['domain'] = trim($exploded[count($exploded) - 3]);
        $queryArray['ip'] = trim($exploded[count($exploded) - 1]);
        array_push($recent, $queryArray);

    }
    return array_reverse($recent);
}

function findQueriesAll($var)
{
    return strpos($var, ": query[") || strpos($var, "gravity.list") || strpos($var, ": forwarded") !== false;
}

function findQueries($var)
{
    return strpos($var, ": query[") !== false;
}

function findAds($var)
{
    $exploded = explode(" ", $var);
    $tmp = $exploded[count($exploded) - 4];
    $tmp2 = $exploded[count($exploded) - 5];
    //filter out bad names and host file reloads:
    return (substr($tmp, strlen($tmp) - 12, 12) == "gravity.list" && $tmp2 != "read");
}

function findForwards($var)
{
    return strpos($var, ": forwarded") !== false;
}

function hasHostName($var)
{
    global $hosts;
    foreach ($hosts as $host) {
        $x = preg_split('/\s+/', $host);
        if ($var == $x[0]) {
            $var = $x[1] . "($var)";
        }
    }
    return $var;
}

function getVersions()
{
    $version['pihole'] = exec("cd /etc/.pihole/ && git describe --tags --abbrev=0");
    $version['pihole_webinterface'] = exec("cd /var/www/html/admin/ && git describe --tags --abbrev=0");
    return $version;
}

function getStatus()
{
    $pistatus = exec('pgrep dnsmasq | wc -l');

    $status['dnsmasq'] = ($pistatus > '0') ? true : false;
    /**
     * Support for the on / off button
     * More info see http://thetimmy.silvernight.org/pages/endisbutton/
     */
    $listFileExists = file_exists('/etc/pihole/gravity.list');
    $status['blackhole'] = ($listFileExists) ? true : false;
    return $status;
}

function getTemp()
{
    $cmd = "echo $((`cat /sys/class/thermal/thermal_zone0/temp|cut -c1-2`)).$((`cat /sys/class/thermal/thermal_zone0/temp|cut -c3-4`))";
    $output = shell_exec($cmd);
    $output = str_replace(["\r\n", "\r", "\n"], "", $output);
    return $output;
}

function getMemoryStats()
{
    exec('free -mo', $out);
    preg_match_all('/\s+([0-9]+)/', $out[1], $matches);
    list($total, $used, $free, $shared, $buffers, $cached) = $matches[1];
    $memory = array();
    $memory['total'] = $total;
    $memory['used'] = $used;
    $memory['free'] = $free;
    $memory['shared'] = $shared;
    $memory['buffers'] = $buffers;
    $memory['cached'] = $cached;
    return $memory;
}

function getDiskStats()
{

    exec("df -x tmpfs -x devtmpfs -T ", $df);
    array_shift($df);
    $mounts = array();
    foreach ($df as $disks) {
        $split = preg_split('/\s+/', $disks);
        $mounts[] = array(
            'disk' => $split[0],
            'mount' => $split[6],
            'type' => $split[1],
            'bytes_total' => $split[2],
            'bytes_used' => $split[3],
            'bytes_free' => $split[4],
            'percent' => $split[5],
        );
    }

    $disk['free'] = disk_free_space('/');
    $disk['total'] = disk_total_space('/');
    $disk['mounts'] = $mounts;
    return $disk;
}


function ifconfig()
{
    exec("/sbin/ifconfig", $data);
    $data = implode($data, "\n");
    $interfaces = array();
    foreach (preg_split("/\n\n/", $data) as $int) {
        preg_match("/^([A-z]*\d)\s+Link\s+encap:([A-z]*)\s+HWaddr\s+([A-z0-9:]*).*" .
            "inet addr:([0-9.]+).*Bcast:([0-9.]+).*Mask:([0-9.]+).*" .
            "MTU:([0-9.]+).*Metric:([0-9.]+).*" .
            "RX packets:([0-9.]+).*errors:([0-9.]+).*dropped:([0-9.]+).*overruns:([0-9.]+).*frame:([0-9.]+).*" .
            "TX packets:([0-9.]+).*errors:([0-9.]+).*dropped:([0-9.]+).*overruns:([0-9.]+).*carrier:([0-9.]+).*" .
            "RX bytes:([0-9.]+).*\((.*)\).*TX bytes:([0-9.]+).*\((.*)\)" .
            "/ims", $int, $regex);

        if (!empty($regex)) {
            $interface = array();

            $interface = array();
            $interface['name'] = $regex[1];
            $interface['type'] = $regex[2];
            $interface['mac'] = $regex[3];
            $interface['ip'] = $regex[4];
            $interface['broadcast'] = $regex[5];
            $interface['netmask'] = $regex[6];
            $interface['mtu'] = $regex[7];
            $interface['metric'] = $regex[8];

            $interface['rx']['packets'] = $regex[9];
            $interface['rx']['errors'] = $regex[10];
            $interface['rx']['dropped'] = $regex[11];
            $interface['rx']['overruns'] = $regex[12];
            $interface['rx']['frame'] = $regex[13];
            $interface['rx']['bytes'] = $regex[19];
            $interface['rx']['hbytes'] = $regex[20];

            $interface['tx']['packets'] = $regex[14];
            $interface['tx']['errors'] = $regex[15];
            $interface['tx']['dropped'] = $regex[16];
            $interface['tx']['overruns'] = $regex[17];
            $interface['tx']['carrier'] = $regex[18];
            $interface['tx']['bytes'] = $regex[21];
            $interface['tx']['hbytes'] = $regex[22];

            $interfaces[] = $interface;
        }
    }
    return $interfaces;
}

function getProcesses($num = 5)
{
    $processes = array();
    $cmd = 'ps aux | sort -nrk 3,3 | head -n ' . $num;
    exec($cmd, $results);
    if ($results) {
        foreach ($results as $result) {
            preg_match('/([a-z0-9-]+)\s+([a-z0-9-]+)\s+([0-9.]+)\s+([0-9.]+)\s+([0-9]+)\s+([0-9]+)\s+([a-z?]+)\s+([A-z])\s+([A-z0-9:]+)\s+([0-9:]+)\s(.*)/', $result, $process);

            $process = array_splice($process, 1);
            if (isset($process[1])) {
                list($p['user'], $p['pid'], $p['cpu_usage'], $p['memory_usage'],
                    $p['virtual_memory_usage'], $p['resident_set_size'], $p['tty'],
                    $p['stat'], $p['start'], $p['time'], $p['command']) = $process;
                $processes[] = $p;
            }
        }
    }
    return $processes;
}

?>
