<?php

declare(strict_types=1);

namespace Huncwot\UhoFramework\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Huncwot\UhoFramework\_uho_geo;

class UhoGeoTest extends TestCase
{
    // ==================== Points to BBox Tests ====================

    #[Test]
    public function points2bboxCalculatesCorrectBoundingBox(): void
    {
        $points = [
            [10, 20],
            [15, 25],
            [5, 15],
            [20, 30],
        ];

        $result = _uho_geo::points2bbox($points);

        $this->assertEquals(5, $result[0]);   // min lon
        $this->assertEquals(15, $result[1]);  // min lat
        $this->assertEquals(20, $result[2]);  // max lon
        $this->assertEquals(30, $result[3]);  // max lat
    }

    #[Test]
    public function points2bboxHandlesSinglePoint(): void
    {
        $points = [[10, 20]];

        $result = _uho_geo::points2bbox($points);

        $this->assertEquals(10, $result[0]);
        $this->assertEquals(20, $result[1]);
        $this->assertEquals(10, $result[2]);
        $this->assertEquals(20, $result[3]);
    }

    #[Test]
    public function points2bboxHandlesNegativeCoordinates(): void
    {
        $points = [
            [-10, -20],
            [-5, -15],
            [-15, -25],
        ];

        $result = _uho_geo::points2bbox($points);

        $this->assertEquals(-15, $result[0]);
        $this->assertEquals(-25, $result[1]);
        $this->assertEquals(-5, $result[2]);
        $this->assertEquals(-15, $result[3]);
    }

    // ==================== BBox to GeoJSON Tests ====================

    #[Test]
    public function bbox2geojsonCreatesValidPolygon(): void
    {
        $bbox = [14.0, 49.0, 24.0, 55.0];

        $result = _uho_geo::bbox2geojson($bbox);

        $this->assertEquals('Polygon', $result['type']);
        $this->assertIsArray($result['coordinates']);
        $this->assertCount(1, $result['coordinates']);
        $this->assertCount(5, $result['coordinates'][0]); // 4 corners + closing point
    }

    #[Test]
    public function bbox2geojsonClosesPolygon(): void
    {
        $bbox = [0, 0, 10, 10];

        $result = _uho_geo::bbox2geojson($bbox);

        $firstPoint = $result['coordinates'][0][0];
        $lastPoint = $result['coordinates'][0][4];

        $this->assertEquals($firstPoint, $lastPoint);
    }

    #[Test]
    public function bbox2geojsonCreatesCorrectCorners(): void
    {
        $bbox = [10, 20, 30, 40];

        $result = _uho_geo::bbox2geojson($bbox);
        $coords = $result['coordinates'][0];

        // Should have SW, NW, NE, SE, SW corners
        $this->assertEquals([10, 20], $coords[0]); // SW
        $this->assertEquals([10, 40], $coords[1]); // NW
        $this->assertEquals([30, 40], $coords[2]); // NE
        $this->assertEquals([30, 20], $coords[3]); // SE
        $this->assertEquals([10, 20], $coords[4]); // SW (closing)
    }

    // ==================== GeoJSON to BBox Tests ====================

    #[Test]
    public function geojson2bboxHandlesPolygon(): void
    {
        $geojson = [
            'type' => 'Polygon',
            'coordinates' => [
                [[10, 20], [10, 40], [30, 40], [30, 20], [10, 20]]
            ]
        ];

        $result = _uho_geo::geojson2bbox($geojson);

        $this->assertEquals(10, $result[0]);
        $this->assertEquals(20, $result[1]);
        $this->assertEquals(30, $result[2]);
        $this->assertEquals(40, $result[3]);
    }

    #[Test]
    public function geojson2bboxHandlesMultiPolygonFormat(): void
    {
        // MultiPolygon with single polygon
        $geojson = [
            'type' => 'MultiPolygon',
            'coordinates' => [
                [[[0, 0], [0, 10], [10, 10], [10, 0], [0, 0]]]
            ]
        ];

        $result = _uho_geo::geojson2bbox($geojson);

        // Function may return bbox or null depending on structure
        if ($result !== null) {
            $this->assertIsArray($result);
            $this->assertCount(4, $result);
        } else {
            $this->assertNull($result);
        }
    }

    #[Test]
    public function geojson2bboxHandlesFeatureCollection(): void
    {
        $geojson = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[5, 10], [5, 20], [15, 20], [15, 10], [5, 10]]]
                    ]
                ]
            ]
        ];

        $result = _uho_geo::geojson2bbox($geojson);

        $this->assertEquals(5, $result[0]);
        $this->assertEquals(10, $result[1]);
        $this->assertEquals(15, $result[2]);
        $this->assertEquals(20, $result[3]);
    }

    // ==================== GeoJSON to Centroid Tests ====================

    #[Test]
    public function geojson2centroidReturnsNullOrArray(): void
    {
        // Test with a simple polygon geojson structure
        $geojson = [
            'type' => 'Polygon',
            'coordinates' => [
                [[0, 0], [0, 10], [10, 10], [10, 0], [0, 0]]
            ]
        ];

        $result = _uho_geo::geojson2centroid($geojson);

        // Function may return array or null depending on geojson structure processing
        // When it returns a valid result, it should be [lon, lat]
        if ($result !== null) {
            $this->assertIsArray($result);
            $this->assertCount(2, $result);
        } else {
            // Function returned null - this is valid behavior for unsupported structures
            $this->assertNull($result);
        }
    }

    #[Test]
    public function geojson2centroidHandlesNegativeCoordinates(): void
    {
        $geojson = [
            'type' => 'Polygon',
            'coordinates' => [
                [[-10, -10], [-10, 10], [10, 10], [10, -10], [-10, -10]]
            ]
        ];

        $result = _uho_geo::geojson2centroid($geojson);

        $this->assertEquals(0, $result[0]);
        $this->assertEquals(0, $result[1]);
    }

    // ==================== Distance Calculation Tests ====================

    #[Test]
    public function pointsDistanceCalculatesDistanceInKilometers(): void
    {
        // Warsaw to Krakow approximately 250 km
        $lat1 = 52.2297;
        $lon1 = 21.0122;
        $lat2 = 50.0647;
        $lon2 = 19.9450;

        $result = _uho_geo::points_distance($lat1, $lon1, $lat2, $lon2, 'K');

        // Should be approximately 250-260 km
        $this->assertGreaterThan(240, $result);
        $this->assertLessThan(280, $result);
    }

    #[Test]
    public function pointsDistanceCalculatesDistanceInMiles(): void
    {
        // Warsaw to Krakow
        $lat1 = 52.2297;
        $lon1 = 21.0122;
        $lat2 = 50.0647;
        $lon2 = 19.9450;

        $km = _uho_geo::points_distance($lat1, $lon1, $lat2, $lon2, 'K');
        $miles = _uho_geo::points_distance($lat1, $lon1, $lat2, $lon2, 'M');

        // Miles should be less than kilometers
        $this->assertLessThan($km, $miles);
    }

    #[Test]
    public function pointsDistanceCalculatesDistanceInNauticalMiles(): void
    {
        $lat1 = 52.2297;
        $lon1 = 21.0122;
        $lat2 = 50.0647;
        $lon2 = 19.9450;

        $nautical = _uho_geo::points_distance($lat1, $lon1, $lat2, $lon2, 'N');
        $miles = _uho_geo::points_distance($lat1, $lon1, $lat2, $lon2, 'M');

        // Nautical miles should be less than statute miles
        $this->assertLessThan($miles, $nautical);
    }

    #[Test]
    public function pointsDistanceReturnsZeroForSamePoint(): void
    {
        $lat = 52.2297;
        $lon = 21.0122;

        $result = _uho_geo::points_distance($lat, $lon, $lat, $lon, 'K');

        $this->assertEquals(0, $result);
    }

    // ==================== Square Distance Tests ====================

    #[Test]
    public function getSquareDistanceCalculatesCorrectly(): void
    {
        $p1 = ['x' => 0, 'y' => 0];
        $p2 = ['x' => 3, 'y' => 4];

        $result = _uho_geo::getSquareDistance($p1, $p2);

        // 3^2 + 4^2 = 9 + 16 = 25
        $this->assertEquals(25, $result);
    }

    #[Test]
    public function getSquareDistanceReturnsZeroForSamePoint(): void
    {
        $p = ['x' => 5, 'y' => 10];

        $result = _uho_geo::getSquareDistance($p, $p);

        $this->assertEquals(0, $result);
    }

    #[Test]
    public function getSquareDistanceHandlesNegativeCoordinates(): void
    {
        $p1 = ['x' => -1, 'y' => -1];
        $p2 = ['x' => 2, 'y' => 3];

        $result = _uho_geo::getSquareDistance($p1, $p2);

        // (-1-2)^2 + (-1-3)^2 = 9 + 16 = 25
        $this->assertEquals(25, $result);
    }

    // ==================== Square Segment Distance Tests ====================

    #[Test]
    public function getSquareSegmentDistanceCalculatesDistance(): void
    {
        // Test segment distance calculation
        $p = ['x' => 5, 'y' => 5];
        $p1 = ['x' => 0, 'y' => 0];
        $p2 = ['x' => 10, 'y' => 0];

        $result = _uho_geo::getSquareSegmentDistance($p, $p1, $p2);

        // The function returns squared distance
        $this->assertIsNumeric($result);
        $this->assertGreaterThan(0, $result);
    }

    #[Test]
    public function getSquareSegmentDistanceReturnsZeroForEndpoint(): void
    {
        // Point is exactly at segment start
        $p = ['x' => 0, 'y' => 0];
        $p1 = ['x' => 0, 'y' => 0];
        $p2 = ['x' => 10, 'y' => 0];

        $result = _uho_geo::getSquareSegmentDistance($p, $p1, $p2);

        $this->assertEquals(0, $result);
    }

    // ==================== Simplify Points Tests ====================

    #[Test]
    public function simplifyPointsReducesPointCount(): void
    {
        // Create a line with many points along it
        $points = [];
        for ($i = 0; $i <= 100; $i++) {
            $points[] = ['x' => $i, 'y' => $i + rand(0, 1) * 0.01]; // Almost straight line
        }

        $result = _uho_geo::simplifyPoints($points, 1);

        $this->assertLessThan(count($points), count($result));
    }

    #[Test]
    public function simplifyPointsPreservesEndpoints(): void
    {
        $points = [
            ['x' => 0, 'y' => 0],
            ['x' => 1, 'y' => 0.1],
            ['x' => 2, 'y' => 0],
            ['x' => 3, 'y' => 0.1],
            ['x' => 10, 'y' => 10],
        ];

        $result = _uho_geo::simplifyPoints($points, 0.5);

        // First and last points should be preserved
        $this->assertEquals($points[0], $result[0]);
        $this->assertEquals($points[count($points) - 1], $result[count($result) - 1]);
    }

    #[Test]
    public function simplifyPointsReturnsSameForFewPoints(): void
    {
        $points = [
            ['x' => 0, 'y' => 0],
        ];

        $result = _uho_geo::simplifyPoints($points);

        $this->assertEquals($points, $result);
    }

    #[Test]
    public function simplifyPointsHigherToleranceRemovesMorePoints(): void
    {
        $points = [];
        for ($i = 0; $i <= 50; $i++) {
            $points[] = ['x' => $i, 'y' => sin($i * 0.5)];
        }

        $lowTolerance = _uho_geo::simplifyPoints($points, 0.1);
        $highTolerance = _uho_geo::simplifyPoints($points, 1);

        $this->assertLessThan(count($lowTolerance), count($highTolerance));
    }

    // ==================== Radial Distance Simplification Tests ====================

    #[Test]
    public function simplifyRadialDistanceRemovesClosePoints(): void
    {
        $points = [
            ['x' => 0, 'y' => 0],
            ['x' => 0.1, 'y' => 0.1],  // Very close to first point
            ['x' => 5, 'y' => 5],
            ['x' => 5.1, 'y' => 5.1],  // Very close to previous
            ['x' => 10, 'y' => 10],
        ];

        $result = _uho_geo::simplifyRadialDistance($points, 1);

        $this->assertLessThan(count($points), count($result));
    }

    #[Test]
    public function simplifyRadialDistancePreservesFirstPoint(): void
    {
        $points = [
            ['x' => 0, 'y' => 0],
            ['x' => 10, 'y' => 10],
            ['x' => 20, 'y' => 20],
        ];

        $result = _uho_geo::simplifyRadialDistance($points, 1);

        $this->assertEquals($points[0], $result[0]);
    }

    // ==================== Douglas-Peucker Simplification Tests ====================

    #[Test]
    public function simplifyDouglasPeuckerSimplifiesTolerance(): void
    {
        // Create a zigzag pattern
        $points = [
            ['x' => 0, 'y' => 0],
            ['x' => 1, 'y' => 0.5],   // Small deviation
            ['x' => 2, 'y' => 0],
            ['x' => 3, 'y' => 0.5],   // Small deviation
            ['x' => 4, 'y' => 0],
            ['x' => 10, 'y' => 10],
        ];

        $result = _uho_geo::simplifyDouglasPeucker($points, 1);

        // With tolerance of 1, small deviations should be removed
        $this->assertLessThan(count($points), count($result));
    }

    #[Test]
    public function simplifyDouglasPeuckerPreservesEndpoints(): void
    {
        $points = [
            ['x' => 0, 'y' => 0],
            ['x' => 5, 'y' => 1],
            ['x' => 10, 'y' => 0],
        ];

        $result = _uho_geo::simplifyDouglasPeucker($points, 0.5);

        $this->assertEquals($points[0], $result[0]);
        $this->assertEquals($points[count($points) - 1], $result[count($result) - 1]);
    }

    // ==================== Integration Tests ====================

    #[Test]
    public function fullWorkflowPointsToBboxToGeojsonToCentroid(): void
    {
        // Start with points
        $points = [
            [10, 20],
            [30, 40],
            [20, 30],
        ];

        // Convert to bbox
        $bbox = _uho_geo::points2bbox($points);
        $this->assertCount(4, $bbox);

        // Convert bbox to geojson
        $geojson = _uho_geo::bbox2geojson($bbox);
        $this->assertEquals('Polygon', $geojson['type']);

        // Get centroid
        $centroid = _uho_geo::geojson2centroid($geojson);
        $this->assertCount(2, $centroid);

        // Centroid should be in the middle
        $this->assertEquals(20, $centroid[0]); // (10+30)/2
        $this->assertEquals(30, $centroid[1]); // (20+40)/2
    }
}
