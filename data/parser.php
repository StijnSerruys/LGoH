<?php

function getHeroesSpreadsheetData()
{
    $spreadsheetURL = "https://docs.google.com/spreadsheets/d/1DK3Gxzy9AQL28kWtfDkHWG_5rqHpDriOKT4L0LHK5ks/edit#gid=476538225&single=true&output=tsv";

    $data = file_get_contents($spreadsheetURL);
    $data = mb_convert_encoding($data, 'UTF-8', 'ASCII');
    $data = str_replace("\r", "", $data);
    file_put_contents('heroes_all_test.tsv', $data);
}

/**
 * @param $filename
 * @param $delimiter
 *
 * @return array|bool
 */
function collectHeroesData($filename, $delimiter = ',')
{
    $data = parseCSV($filename, $delimiter);

    $dbData = [];
    $ids = [];

    foreach ($data as $row) {
        $rarity = strlen($row['stars']);
        $affinity = ucfirst($row['affinity']);
        $name = $row['name'];
        $liderAbility = $row['leader ability'];
        $id = generateId($row['name'], $row['stars']);

        if (in_array($id, $ids)) {
            var_dump("Id '{$id}', created by '{$row['name']} {$row['stars']}', already exists");
            die();
        }
        $ids[] = $id;

        list(
            $leaderAbilityName,
            $leaderAbilityDescription,
            $leaderAbilityModifiers,
            $leaderAbilityTarget
            ) = extractLiderAbility($liderAbility, $name);

        $dbData[] = [
            'coreId' => $id,
            'name' => $name,
            'affinity' => $affinity,
            'type' => $row['class'],
            'species' => $row['race'],
            'attack' => (int)$row['attack'],
            'recovery' => (int)$row['recovery'],
            'health' => (int)$row['health'],
            'rarity' => $rarity,
            'awakening' => 5,
            'eventSkills' => array_merge(
                !empty($row['slayer']) && preg_match('/^(\d)x$/', $row['slayer'], $sMatches)
                    ? ['Slayer' => (int)$sMatches[1]] : [],
                !empty($row['bounty hunter']) && preg_match('/^(\d)x$/', $row['bounty hunter'], $bhMatches)
                    ? ['Bounty Hunter' => (int)$bhMatches[1]] : [],
                !empty($row['commander']) && preg_match('/^(\d)x$/', $row['commander'], $cMatches)
                    ? ['Commander' => (int)$cMatches[1]] : [],
                !empty($row['warden']) ? ['Warden' => true] : []
            ) ?: new stdClass(), //force object instead of empty array
            'defenderSkill' => $row['defender skill'],
            'counterSkill' => $row['counter skill'],
            'leaderAbility' => [
                'name' => $leaderAbilityName,
                'description' => $leaderAbilityDescription,
                'modifiers' => $leaderAbilityModifiers,
                'target' => $leaderAbilityTarget,
            ],
            'evolveFrom' => !empty($row['evolve from']) ? generateId($row['evolve from']) : '',
            'evolveInto' => !empty($row['evolve into']) ? generateId($row['evolve into']) : '',
        ];
    }

    return $dbData;
}

/**
 * @param $filename
 * @param $delimiter
 * @return array
 */
function parseCSV($filename, $delimiter)
{
    if (!file_exists($filename) || !is_readable($filename)) {
        die('Cannot read the file!');
    }

    $header = null;
    $data = array();
    if (($handle = fopen($filename, 'r')) !== false) {
        while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
            if (!$header) {
                $header = $row;
            } elseif (count($header) != count($row)) {
                var_dump('Mismatched columns!', $header, $row);
                die();
            } else {
                $data[] = array_combine($header, $row);
            }
        }
        fclose($handle);
    }

    return $data;
}

/**
 * @param string $stats
 * @return string
 */
function convertStats($stats)
{
    return str_replace(
        ['Damage', 'ATK', 'REC', 'RCV', 'HP'],
        ['attack', 'attack', 'recovery', 'recovery', 'health'],
        $stats
    );
}

/**
 * @param $liderAbilityDescription
 * @param $heroName
 * @return array
 */
function extractLiderAbility($liderAbilityDescription, $heroName)
{
    $leaderAbilityMatches = [];
    $leaderAbilityValues = [];
    if (preg_match(
        '/^([^:]+): ((\d+)% ((Damage|HP|REC)( and (Damage|HP|REC))?) for (all )?((\w+( \w+)?) Heroes))$/',
        $liderAbilityDescription,
        $leaderAbilityMatches
    )) {
        $leaderAbilityTarget = rtrim($leaderAbilityMatches[10], 's');
        $leaderAbilityTarget = strpos($leaderAbilityTarget, ' ') !== false
            ? explode(' ', $leaderAbilityTarget)
            : $leaderAbilityTarget;

        foreach (explode(' and ', convertStats($leaderAbilityMatches[4])) as $stat) {
            $leaderAbilityValues[$stat] = $leaderAbilityMatches[3] / 100;
        }
    } elseif (preg_match(
        '/^([^:]+): ((\d+)% ((Damage|HP|REC)( and (Damage|HP|REC))?) for (all )?((\w+( \w+)?) Heroes in GvG attacks))$/',
        $liderAbilityDescription,
        $leaderAbilityMatches
    )) {
        // maybe someday
        $leaderAbilityTarget = [];

        foreach (explode(' and ', convertStats($leaderAbilityMatches[4])) as $stat) {
            $leaderAbilityValues[$stat] = $leaderAbilityMatches[3] / 100;
        }
    } elseif (preg_match(
        '/^([^:]+): ((\d+)% ((Damage|HP|REC)( and (Damage|HP|REC))?) for (all )?((\w+( \w+)?) Bounty Hunters))$/',
        $liderAbilityDescription,
        $leaderAbilityMatches
    )) {
        $leaderAbilityTarget = rtrim($leaderAbilityMatches[10], 's');
        $leaderAbilityTarget = explode(' ', $leaderAbilityTarget);
        $leaderAbilityTarget[] = 'Bounty Hunter';

        foreach (explode(' and ', convertStats($leaderAbilityMatches[4])) as $stat) {
            $leaderAbilityValues[$stat] = $leaderAbilityMatches[3] / 100;
        }
    } elseif (preg_match(
        '/^([^:]+): ((\d+)% (ATK|HP|REC), (\d+)% (ATK|HP|REC) and (ATK|HP|REC) for (\w+( \w+)?) Heroes)$/',
        $liderAbilityDescription,
        $leaderAbilityMatches
    )) {
        $leaderAbilityTarget = rtrim($leaderAbilityMatches[8], 's');
        $leaderAbilityTarget = strpos($leaderAbilityTarget, ' ') !== false
            ? explode(' ', $leaderAbilityTarget)
            : $leaderAbilityTarget;

        $leaderAbilityValues[convertStats($leaderAbilityMatches[4])] = $leaderAbilityMatches[3] / 100;
        $leaderAbilityValues[convertStats($leaderAbilityMatches[6])] = $leaderAbilityMatches[5] / 100;
        $leaderAbilityValues[convertStats($leaderAbilityMatches[7])] = $leaderAbilityMatches[5] / 100;
    } elseif (preg_match(
        '/^([^:]+): ((\d+)% (Damage|HP|RCV|REC), (Damage|HP|RCV|REC) and (Damage|HP|RCV|REC) for (\w+( \w+)?) Heroes)$/',
        $liderAbilityDescription,
        $leaderAbilityMatches
    )) {
        $leaderAbilityTarget = rtrim(trim($leaderAbilityMatches[8]), 's');
        $leaderAbilityTarget = strpos($leaderAbilityTarget, ' ') !== false
            ? explode(' ', $leaderAbilityTarget)
            : $leaderAbilityTarget;

        $leaderAbilityValues[convertStats($leaderAbilityMatches[4])] = $leaderAbilityMatches[3] / 100;
        $leaderAbilityValues[convertStats($leaderAbilityMatches[5])] = $leaderAbilityMatches[3] / 100;
        $leaderAbilityValues[convertStats($leaderAbilityMatches[6])] = $leaderAbilityMatches[3] / 100;
    } else {
        var_dump(
            "Invalid leader ability format for {$heroName} ({$liderAbilityDescription})",
            $leaderAbilityMatches
        );
        die();
    }

    return array($leaderAbilityMatches[1], $leaderAbilityMatches[2], $leaderAbilityValues, $leaderAbilityTarget);
}

function generateId($name, $stars = null)
{
    $idLen = 8;

    if (null !== $stars) {
        return substr(md5("{$name} {$stars}"), 0, $idLen);
    } else {
        return substr(md5($name), 0, $idLen);
    }
}

function collectSkillsData($filename, $delimiter = ',')
{
    $data = parseCSV($filename, $delimiter);

    $dbData = [];
    foreach ($data as $row) {
        if (!empty($row['Defender Skill'])) {
            $dbData['defenderSkill'][$row['Defender Skill']] = $row['Defender Skill Description'];
        }

        if (!empty($row['Counter Skill'])) {
            $dbData['counterSkill'][$row['Counter Skill']] = $row['Counter Skill Description'];
        }
    }

    return $dbData;
}

getHeroesSpreadsheetData();
/*file_put_contents('heroes_all.json', json_encode(collectHeroesData('heroes_all.tsv', "\t"), JSON_PRETTY_PRINT));
file_put_contents('skills_all.json', json_encode(collectSkillsData('heroes_skills.tsv', "\t"), JSON_PRETTY_PRINT));
*/