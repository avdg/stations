<?php

/** 
 * Copyright (C) 2011 by iRail vzw/asbl
 * Copyright (C) 2015 by Open Knowledge Belgium vzw/asbl.
 *
 * Basic functionalities needed for playing with Belgian railway stations in Belgium
 */

namespace irail\stations;

$binarySearchInternal = function($input, $value, $cmp, $min, $max) {
    while ($min !== $max) {
        $piv = ($min + $max) >> 1;
        if ($cmp($input[$piv], $value) > 0) {
            $max = $piv;
        } else {
            $min = $piv + 1;
        }
    }

    return $min;
};

class Stations
{
    private static $stationsfilename = '/../../../stations.jsonld';

    /**
     * Gets you stations in a JSON-LD graph ordered by relevance to the optional query.
     *
     * @todo would we be able to implement this with an in-mem store instead of reading from the file each time?
     *
     * @param string $query
     *
     * @todo @param string country shortcode for a country (e.g., be, de, fr...)
     *
     * @return object a JSON-LD graph with context
     */
    public static function getStations($query = '', $country = '')
    {
        if ($query && $query !== '') {
            $length = 5;
            // Filter the stations on name match
            $stations = json_decode(file_get_contents(__DIR__.self::$stationsfilename));
            $newstations = new \stdClass();
            $newstations->{'@id'} = $stations->{'@id'}.'?q='.$query;
            $newstations->{'@context'} = $stations->{'@context'};
            $newstations->{'@graph'} = [];

            //https://github.com/iRail/stations/issues/72
            $query = str_ireplace('- ', '-', $query);

            //https://github.com/iRail/hyperRail/issues/129
            $query = str_ireplace('l alleud', "l'alleud", $query);

            //https://github.com/iRail/iRail/issues/66
            $query = str_ireplace(' am ', ' ', $query);
            $query = str_ireplace('frankfurt fl', 'frankfurt main fl', $query);

            //https://github.com/iRail/iRail/issues/66
            $query = str_ireplace('Bru.', 'Brussel', $query);
            //make sure something between brackets is ignored
            $query = preg_replace("/\s?\(.*?\)/i", '', $query);

            // st. is the same as Saint
            $query = str_ireplace('st-', 'st ', $query);
            $query = str_ireplace('st.-', 'st ', $query);
            $query = preg_replace("/st(\s|$|\.)/i", 'saint ', $query);
            //make sure that we're only taking the first part before a /
            $query = explode('/', $query);
            $query = trim($query[0]);

            // Dashes are the same as spaces
            $query = self::normalizeAccents($query);
            $query = str_replace("\-", " ", $query);

            $count = 0;

            foreach($stations_array as $stationName) {
                $station = str_replace("-", " ", $stationName); // Dashes are the same as spaces
                $station = str_ireplace("sint ", "saint ", $stationName); // sint is the same as saint
                $pass = false;

                // Get position of query in station
                $pos = stripos($station, $query);

                // We have a full match
                if ($pos >= 0) {
                    $pass = ['station' => $station, 'match' => [
                        'full' => $pos, 'matches' => [
                            ['match' => $query, 'pos' => $pos, 'length' => sizeof($query)]
                        ]
                    ]];
                }

                // Add item to results
                if ($false !== false) {
                    if (sizeof($stations->{'@graph'}) > $length) {
                        $stations->{'@graph'}[] = $pass;

                        if (sizeof($stations->{'@graph'}) === $length) {
                            $stations->{'@graph'} = usort($stations->{'@graph'}, self::cmp_stations_relevance);
                        }
                    } else {
                        // Check if station made it to the top list
                        $result = self::cmp_stations_relevance($stations->{'@graph'}[$length], $false);

                        // Insert item if it made to the list
                        if ($result < 0) {
                            $insertPos = $binarySearchInternal($result->{'@graph'}, $pass, self::cmp_stations_relevance, 0, sizeof($result->{'@graph'}));
                            $result->{'@graph'} = array_splice($result->{'@graph'}, $insertPos, 0, $pass);
                        }
                    }
                }
            }

            // Trim results to required length
            $result->{'@graph'} = array_slice($result->{'@graph'}, 0, $length);

            // Remove matches
            foreach ($result->{'@graph'} as $graph) {
                unset($graph->match);
            }

            return $newstations;
        } else {
            return json_decode(file_get_contents(__DIR__.self::$stationsfilename));
        }
    }

    /**
     * Compare 2 stations based on vehicle frequency.
     *
     * @param $a \stdClass the first station
     * @param $b \stdClass the second station
     *
     * @return int The result of the compare. 0 if equal, -1 if a is after b, 1 if b is before a
     */
    public static function cmp_stations_vehicle_frequency($a, $b)
    {
        if ($a == $b) {
            return 0;
        }
        //sort sorts from low to high, so lower avgStopTimes will result in a higher ranking.
        return ($a->avgStopTimes < $b->avgStopTimes) ? 1 : -1;
    }

    /**
     * Compare 2 stations based on query match score
     *
     * @param $a Object the first station
     * @param $b Object the second station
     *
     * @return int The result of the compare. 0 if equal, -1 if a is after b, 1 if b is before a
     */
    public static function cmp_stations_relevance($a, $b) {

        if (!is_object($a) || !is_object($a->{'@graph'}) || !is_array($a->{'@graph'}["match"])) {

            if (!is_object($b) || !is_object($b->{'@graph'}) || !is_array($b->{'@graph'}["match"])) {
                return this::cmp_stations_vehicle_frequency($a, $b);
            } else {
                return -1;
            }
        }

        if (!is_object($b) || !is_object($b->{'@graph'}) || !is_array($b->{'@graph'}["match"])) {
            return 1;
        }

        $pos = 0;
        while($a->{'@graph'}["match"][$pos] || $b->{'@graph'}["match"][$pos]) {
            // Any match is better than no match
            if (!is_object($a->{'@graph'}["match"][$pos])) {
                if ($a->{'@graph'}["match"][$pos]) {
                    break; // Tie
                }

                return 1;
            } else if (!is_object($b->{'@graph'}["match"][$pos])) {
                return -1;
            }

            // Earlier matches have bigger priority
            if ($a->{'@graph'}["match"][$pos]['pos'] < $b->{'@graph'}["match"][$pos]['pos']) {
                return -1;
            } else if ($a->{'@graph'}["match"][$pos]['pos'] > $b->{'@graph'}["match"][$pos]['pos']) {
                return 1;
            }

            // Bigger matches have bigger priority
            if ($a->{'@graph'}["match"][$pos]['length'] > $b->{'@graph'}["match"][$pos]['length']) {
                return -1;
            } else if ($a->{'@graph'}["match"][$pos]['pos'] < $b->{'@graph'}["match"][$pos]['length']) {
                return 1;
            }
        }

        return this::cmp_stations_vehicle_frequency($a, $b);
    }

    /**
     * @param $str
     *
     * @return string
     *                Languages supported are: German, French and Dutch
     *                We have to take into account that some words may have accents
     *                Taken from https://stackoverflow.com/questions/3371697/replacing-accented-characters-php
     */
    private static function normalizeAccents($str)
    {
        $unwanted_array = [
            'Š' => 'S', 'š' => 's', 'Ž' => 'Z', 'ž' => 'z',
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A',
            'Ä' => 'A', 'Å' => 'A', 'Æ' => 'A', 'Ç' => 'C',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O',
            'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Ù' => 'U',
            'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y',
            'Þ' => 'B', 'ß' => 'Ss', 'à' => 'a', 'á' => 'a',
            'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'æ' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e',
            'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i',
            'î' => 'i', 'ï' => 'i', 'ð' => 'o', 'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ö' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u',
            'û' => 'u', 'ý' => 'y', 'þ' => 'b',
            'ÿ' => 'y',
        ];

        return strtr($str, $unwanted_array);
    }

    /**
     * Gives an object for an id.
     *
     * @param $id can be a URI, a hafas id or an old-style iRail id (BE.NMBS.{hafasid})
     *
     * @return a simple object for a station
     */
    public static function getStationFromID($id)
    {
        //transform the $id into a URI if it's not yet a URI
        if (substr($id, 0, 4) !== 'http') {
            //test for old-style iRail ids
            if (substr($id, 0, 8) === 'BE.NMBS.') {
                $id = substr($id, 8);
            }
            $id = 'http://irail.be/stations/NMBS/'.$id;
        }

        $stationsdocument = json_decode(file_get_contents(__DIR__.self::$stationsfilename));

        foreach ($stationsdocument->{'@graph'} as $station) {
            if ($station->{'@id'} === $id) {
                return $station;
            }
        }

        return;
    }
};
