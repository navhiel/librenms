<?php

$port = mres($_GET['id']);
if ($_GET['stat']) {
    $stat = mres($_GET['stat']);
}
else {
    $stat = 'bits';
}

$sort = mres($_GET['sort']);

if (is_numeric($_GET['topn'])) {
    $topn = $_GET['topn'];
}
else {
    $topn = '10';
}

require 'includes/graphs/common.inc.php';

if ($stat == 'pkts') {
    $units      = 'pps';
    $unit       = 'p';
    $multiplier = '1';
    $colours    = 'purples';
    $prefix     = 'P';
    if ($sort == 'in') {
        $sort = 'cipMacHCSwitchedPkts_input_rate';
    }
    else if ($sort == 'out') {
        $sort = 'cipMacHCSwitchedPkts_output_rate';
    }
    else {
        $sort = 'bps';
    }
}
else if ($stat == 'bits') {
    $units      = 'bps';
    $unit       = 'B';
    $multiplier = '8';
    $colours    = 'greens';
    if ($sort == 'in') {
        $sort = 'cipMacHCSwitchedBytes_input_rate';
    }
    else if ($sort == 'out') {
        $sort = 'cipMacHCSwitchedBytes_output_rate';
    }
    else {
        $sort = 'bps';
    }
}//end if

$accs = dbFetchRows(
    "SELECT *, (M.cipMacHCSwitchedBytes_input_rate + M.cipMacHCSwitchedBytes_output_rate) AS bps,
        (M.cipMacHCSwitchedPkts_input_rate + M.cipMacHCSwitchedPkts_output_rate) AS pps
        FROM `mac_accounting` AS M, `ports` AS I, `devices` AS D WHERE M.port_id = ?
        AND I.port_id = M.port_id AND D.device_id = I.device_id ORDER BY $sort DESC LIMIT 0,".$topn,
    array($port)
);

$pluses       = '';
$iter         = '0';
$rrd_options .= " COMMENT:'                                     In\: Current     Maximum      Total      Out\: Current     Maximum     Total\\\\n'";

foreach ($accs as $acc) {
    $this_rrd = rrd_name($acc['hostname'], array('cip', $acc['ifIndex'], $acc['mac']));
    if (is_file($this_rrd)) {
        $mac  = formatmac($acc['mac']);
        $name = $mac;
        $addy = dbFetchRow('SELECT * FROM ipv4_mac where mac_address = ? AND port_id = ?', array($acc['mac'], $acc['port_id']));

        if ($addy) {
            $name = $addy['ipv4_address'].' ('.$mac.')';
            $peer = dbFetchRow(
                'SELECT * FROM ipv4_addresses AS A, ports AS I, devices AS D
              WHERE A.ipv4_address = ? AND I.port_id = A.port_id AND D.device_id = I.device_id',
                array($addy['ipv4_address'])
            );
            if ($peer) {
                $name = $peer['hostname'].' '.makeshortif($peer['ifDescr']).' ('.$mac.')';
            }

            if (dbFetchCell("SELECT count(*) FROM bgpPeers WHERE device_id = '".$acc['device_id']."' AND bgpPeerIdentifier = ?", array($addy['ipv4_address']))) {
                $peer_info = dbFetchRow('SELECT * FROM bgpPeers WHERE device_id = ? AND bgpPeerIdentifier = ?', array($acc['device_id'], $addy['ipv4_address']));
                $name     .= ' - AS'.$peer_info['bgpPeerRemoteAs'];
            }

            if ($peer_info) {
                $asn    = 'AS'.$peer_info['bgpPeerRemoteAs'];
                $astext = $peer_info['astext'];
            }
            else {
                unset($as);
                unset($astext);
                unset($asn);
            }
        }//end if

        $this_id = str_replace('.', '', $acc['mac']);
        if (!$config['graph_colours'][$colours][$iter]) {
            $iter = 0;
        }

        $colour       = $config['graph_colours'][$colours][$iter];
        $descr        = rrdtool_escape($name, 36);
        $rrd_options .= ' DEF:in'.$this_id."=$this_rrd:".$prefix.'IN:AVERAGE ';
        $rrd_options .= ' DEF:out'.$this_id."temp=$this_rrd:".$prefix.'OUT:AVERAGE ';
        $rrd_options .= ' CDEF:inB'.$this_id.'=in'.$this_id.",$multiplier,* ";
        $rrd_options .= ' CDEF:outB'.$this_id.'temp=out'.$this_id."temp,$multiplier,*";
        $rrd_options .= ' CDEF:outB'.$this_id.'=outB'.$this_id.'temp,-1,*';
        $rrd_options .= ' CDEF:octets'.$this_id.'=inB'.$this_id.',outB'.$this_id.'temp,+';
        $rrd_options .= ' VDEF:totin'.$this_id.'=inB'.$this_id.',TOTAL';
        $rrd_options .= ' VDEF:totout'.$this_id.'=outB'.$this_id.'temp,TOTAL';
        $rrd_options .= ' VDEF:tot'.$this_id.'=octets'.$this_id.',TOTAL';
        $rrd_options .= ' AREA:inB'.$this_id.'#'.$colour.":'".$descr."':STACK";
        if ($rrd_optionsb) {
            $stack = 'STACK';
        }

        $rrd_optionsb .= ' AREA:outB'.$this_id.'#'.$colour."::$stack";
        $rrd_options  .= ' GPRINT:inB'.$this_id.":LAST:%6.2lf%s$units";
        $rrd_options  .= ' GPRINT:inB'.$this_id.":MAX:%6.2lf%s$units";
        $rrd_options  .= ' GPRINT:totin'.$this_id.":%6.2lf%s$unit";
        $rrd_options  .= " COMMENT:'    '";
        $rrd_options  .= ' GPRINT:outB'.$this_id."temp:LAST:%6.2lf%s$units";
        $rrd_options  .= ' GPRINT:outB'.$this_id."temp:MAX:%6.2lf%s$units";
        $rrd_options  .= ' GPRINT:totout'.$this_id.":%6.2lf%s$unit\\\\n";
        $iter++;
    }//end if
}//end foreach

$rrd_options .= $rrd_optionsb;
$rrd_options .= ' HRULE:0#999999';
