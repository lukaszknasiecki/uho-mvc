<?php

namespace Huncwot\UhoFramework;

/**
 * This class provides a set of static utility functions for geographical issues
 */

class _uho_geo
{
    private static $initialized = false;

    /**
     * Class constructor
     */
    private static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
    }


    /**
     * Returns centroid from geojson
     *
     * @psalm-return list{mixed, mixed}|null
     */
    public static function geojson2centroid($geojson): array|null
    {
        $bbox = _uho_geo::geojson2bbox($geojson);
        if ($bbox && is_array($bbox) && !empty($bbox[0]) && count($bbox) == 4 && !is_array($bbox[0])) {
            $result = [
                $bbox[0] + ($bbox[2] - $bbox[0]) / 2,
                $bbox[1] + ($bbox[3] - $bbox[1]) / 2
            ];
        } else $result = null;
        return $result;
    }

    /**
     * Returns bbox from geojson
     */
    private static function deArrayPoints(array $items): array
    {
        $result = [];

        foreach ($items as $v) {
            if (is_array($v))
                foreach ($v as $vv) {
                    if (is_array($vv)) {
                        $result = array_merge($result, _uho_geo::deArrayPoints($vv));
                    } else {
                        $result[] = $v;
                        continue;
                    }
                }
            else {
                $result[] = $items;
                continue;
            }
        }

        return $result;
    }

    public static function geojson2bbox($geojson)
    {
        if (@$geojson['type'] == 'Polygon') {
            $geojson = ['type' => 'FeatureCollection', 'features' => [['geometry' => $geojson]]];
        }

        if (@$geojson['type'] == 'MultiPolygon') {
            $points = [];
            foreach ($geojson['coordinates'] as  $v)
                foreach ($v as $vv) {
                    $points = array_merge($points, $vv);
                }

            $bbox = _uho_geo::points2bbox($points);
            if (!empty($bbox[0])) return $bbox;
        }


        if (@$geojson['type'] == 'FeatureCollection') {
            $points = [];

            foreach ($geojson['features'] as $v)
                foreach ($v['geometry']['coordinates'] as $v2)
                    foreach ($v2 as $v3)
                        $points[] = $v3;

            $points = _uho_geo::deArrayPoints($points);
            $bbox = _uho_geo::points2bbox($points);
            if (!empty($bbox[0])) return $bbox;
        }
    }


    /**
     * @return (array[][]|string)[]
     *
     * @psalm-return array{type: 'Polygon', coordinates: list{list{list{mixed, mixed}, list{mixed, mixed}, list{mixed, mixed}, list{mixed, mixed}, list{mixed, mixed}}}}
     */
    public static function bbox2geojson($bbox): array
    {
        $g = [
            'type' => "Polygon",
            "coordinates" =>
            [
                [
                    [$bbox[0], $bbox[1]],
                    [$bbox[0], $bbox[3]],
                    [$bbox[2], $bbox[3]],
                    [$bbox[2], $bbox[1]],
                    [$bbox[0], $bbox[1]]
                ]
            ]
        ];
        return $g;
    }

    public static function points_distance($lat1, $lon1, $lat2, $lon2, $unit): float
    {

        $theta = $lon1 - $lon2;
        $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $miles = $dist * 60 * 1.1515;
        $unit = strtoupper($unit);

        if ($unit == "K") {
            return ($miles * 1.609344);
        } else if ($unit == "N") {
            return ($miles * 0.8684);
        } else {
            return $miles;
        }
    }

    /**
     * @return (mixed|null)[]
     *
     * @psalm-return list{mixed|null, mixed|null, mixed|null, mixed|null}
     */
    public static function points2bbox(array|bool $points): array
    {

        $bbox = [null, null, null, null];

        foreach ($points as $v3) {
            if ($bbox[0] === null || $v3[0] < $bbox[0]) $bbox[0] = $v3[0];
            if ($bbox[2] === null || $v3[0] > $bbox[2]) $bbox[2] = $v3[0];
            if ($bbox[1] === null || $v3[1] < $bbox[1]) $bbox[1] = $v3[1];
            if ($bbox[3] === null || $v3[1] > $bbox[3]) $bbox[3] = $v3[1];
        }

        return $bbox;
    }

    /*
        returns sq distance between points
    */

    public static function getSquareDistance($p1, $p2)
    {
        $dx = $p1['x'] - $p2['x'];
        $dy = $p1['y'] - $p2['y'];
        return $dx * $dx + $dy * $dy;
    }

    /*
        returns segments distance between point
    */

    public static function getSquareSegmentDistance($p, $p1, $p2)
    {
        $x = $p1['x'];
        $y = $p1['y'];

        $dx = $p2['x'] - $x;
        $dy = $p2['y'] - $y;

        if ($dx && $dy) {

            $t = (($p['x'] - $x) * $dx + ($p['y'] - $y) * $dy) / ($dx * $dx + $dy * $dy);

            if ($t > 1) {
                $x = $p2['x'];
                $y = $p2['y'];
            } else if ($t > 0) {
                $x += $dx * $t;
                $y += $dy * $t;
            }
        }

        $dx = $p['x'] - $x;
        $dy = $p['y'] - $y;

        return $dx * $dx + $dy * $dy;
    }

    /*
     distance-based simplification
     */

    /**
     * @psalm-return list{0: mixed, 1?: mixed,...}
     */
    public static function simplifyRadialDistance($points, $sqTolerance): array
    {

        $len = count($points);
        $prevPoint = $points[0];
        $newPoints = array($prevPoint);
        $point = null;


        for ($i = 1; $i < $len; $i++) {
            $point = $points[$i];

            if (_uho_geo::getSquareDistance($point, $prevPoint) > $sqTolerance) {
                array_push($newPoints, $point);
                $prevPoint = $point;
            }
        }

        if ($prevPoint !== $point) {
            array_push($newPoints, $point);
        }

        return $newPoints;
    }

    /*
     Simplification using optimized Douglas-Peucker algorithm with recursion elimination
    */

    /**
     * @psalm-return list{0?: mixed,...}
     */
    public static function simplifyDouglasPeucker($points, $sqTolerance): array
    {

        $len = count($points);

        $markers = array_fill(0, $len - 1, null);
        $first = 0;
        $last = $len - 1;

        $firstStack = array();
        $lastStack = array();

        $newPoints = array();

        $markers[$first] = $markers[$last] = 1;

        while ($last) {

            $maxSqDist = 0;

            for ($i = $first + 1; $i < $last; $i++) {
                $sqDist = _uho_geo::getSquareSegmentDistance($points[$i], $points[$first], $points[$last]);

                if ($sqDist > $maxSqDist) {
                    $index = $i;
                    $maxSqDist = $sqDist;
                }
            }

            if ($maxSqDist > $sqTolerance) {
                $markers[$index] = 1;

                array_push($firstStack, $first);
                array_push($lastStack, $index);

                array_push($firstStack, $index);
                array_push($lastStack, $last);
            }

            $first = array_pop($firstStack);
            $last = array_pop($lastStack);
        }

        for ($i = 0; $i < $len; $i++) {
            if ($markers[$i]) {
                array_push($newPoints, $points[$i]);
            }
        }
        if (count($newPoints) == 2 && $newPoints[0] == $newPoints[1]) $newPoints = [];
        return $newPoints;
    }

    /*
        Simplify Points
    */

    /**
     * @param array[] $points
     * @param float|int $tolerance
     *
     * @psalm-param list{0?: array{x: mixed, y: mixed},...} $points
     * @psalm-param 1|float $tolerance
     */
    public static function simplifyPoints(array $points, int|float $tolerance = 1, bool $highestQuality = false)
    {
        if (count($points) < 2)
            return $points;
        $sqTolerance = $tolerance * $tolerance;
        if (!$highestQuality) {
            $points = _uho_geo::simplifyRadialDistance($points, $sqTolerance);
        }
        $points = _uho_geo::simplifyDouglasPeucker($points, $sqTolerance);

        return $points;
    }


    /*
        Simplify Geojson
    */

    public static function simplifyGeojson($json)
    {
        $json = json_encode($json);
        $json = json_decode($json);

        $tolerance = 0.01;

        foreach ($json->features as $feature_id => $feature) {
            $geometry = $feature->geometry;
            if ($geometry->type == 'MultiPolygon') {
                foreach ($geometry->coordinates as $coordinate_id => $polygons) {
                    foreach ($polygons as $polygon_id => $points) {
                        $tmp_points = array();
                        foreach ($points as $point) {
                            $tmp_points[] = array('x' => $point[0], 'y' => $point[1]);
                        }
                        $simplify_points = _uho_geo::simplifyPoints($tmp_points, $tolerance, true);
                        $simplify_polygon = array();
                        foreach ($simplify_points as $point) {
                            $simplify_polygon[] = array($point['x'], $point['y']);
                        }
                        //if ($simplify_polygon)
                        $json->features[$feature_id]->geometry->coordinates[$coordinate_id][$polygon_id] = $simplify_polygon;
                        //  else unset($json->features[$feature_id]->geometry->coordinates[$coordinate_id][$polygon_id]);
                    }
                }
            } else {
                exit('Error');
            }
        }

        foreach ($json->features as $feature_id => $feature) {
            $geometry = $feature->geometry;
            if ($geometry->type == 'MultiPolygon') {
                foreach ($geometry->coordinates as $coordinate_id => $polygons) {
                    $anyPolygons = false;
                    foreach ($polygons as $polygon_id => $points) {
                        if (count($points) == 3)
                            $json->features[$feature_id]->geometry->coordinates[$coordinate_id][$polygon_id][] = $points[0];
                        if ($points) $anyPolygons = true;
                    }


                    if (!$anyPolygons) unset($geometry->coordinates[$coordinate_id]);
                }
                $json->features[$feature_id]->geometry->coordinates = array_values($json->features[$feature_id]->geometry->coordinates);
            }
        }

        $json = json_encode($json);
        $json = json_decode($json, true);

        return $json;
    }
}
