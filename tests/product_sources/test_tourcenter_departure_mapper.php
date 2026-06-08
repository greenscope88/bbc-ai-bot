<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'TourCenterDepartureMapper.php';

$failures = 0;

function tc_mapper_assert(bool $cond, string $message): void
{
    global $failures;
    if (!$cond) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    } else {
        fwrite(STDOUT, "OK: {$message}\n");
    }
}

tc_mapper_assert(TourCenterDepartureMapper::mapToDepartureId('台北') === 'TPE', '台北 -> TPE');
tc_mapper_assert(TourCenterDepartureMapper::mapToDepartureId('松山') === 'TPE', '松山 -> TPE');
tc_mapper_assert(TourCenterDepartureMapper::mapToDepartureId('桃園') === 'TPE', '桃園 -> TPE');
tc_mapper_assert(TourCenterDepartureMapper::mapToDepartureId('台北出發') === 'TPE', '台北出發 -> TPE');
tc_mapper_assert(TourCenterDepartureMapper::mapToDepartureId('松山出發') === 'TPE', '松山出發 -> TPE');
tc_mapper_assert(TourCenterDepartureMapper::mapToDepartureId('桃園出發') === 'TPE', '桃園出發 -> TPE');
tc_mapper_assert(TourCenterDepartureMapper::mapToDepartureId('台中') === 'TCH', '台中 -> TCH');
tc_mapper_assert(TourCenterDepartureMapper::mapToDepartureId('台南') === 'TNN', '台南 -> TNN');
tc_mapper_assert(TourCenterDepartureMapper::mapToDepartureId('高雄') === 'KHH', '高雄 -> KHH');
tc_mapper_assert(TourCenterDepartureMapper::mapToDepartureId(null) === '', 'null -> empty DepartureID');
tc_mapper_assert(TourCenterDepartureMapper::mapToDepartureId('') === '', 'empty -> empty DepartureID');

tc_mapper_assert(TourCenterDepartureMapper::normalizeDepartureCity('松山') === '台北', 'normalize 松山 -> 台北');
tc_mapper_assert(TourCenterDepartureMapper::normalizeDepartureCity('桃園出發') === '台北', 'normalize 桃園出發 -> 台北');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "OK: test_tourcenter_departure_mapper\n");
exit(0);