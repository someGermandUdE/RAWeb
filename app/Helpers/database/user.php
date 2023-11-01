<?php

use App\Community\Enums\AwardType;
use App\Community\Enums\ClaimStatus;
use App\Community\Enums\TicketState;
use App\Site\Enums\Permissions;
use App\Site\Models\User;

function GetUserData(string $username): ?array
{
    return User::firstWhere('User', $username)?->toArray();
}

function getAccountDetails(?string &$username = null, ?array &$dataOut = []): bool
{
    if (empty($username) || !isValidUsername($username)) {
        return false;
    }

    $query = "SELECT ID, User, EmailAddress, Permissions, RAPoints, RASoftcorePoints, TrueRAPoints,
                     cookie, websitePrefs, UnreadMessageCount, Motto, UserWallActive,
                     APIKey, ContribCount, ContribYield,
                     RichPresenceMsg, LastGameID, LastLogin, LastActivityID,
                     Created, DeleteRequested, Untracked
                FROM UserAccounts
                WHERE User = :username
                AND Deleted IS NULL";

    $dataOut = legacyDbFetch($query, [
        'username' => $username,
    ]);

    if (!$dataOut) {
        return false;
    }

    $username = $dataOut['User'];

    return true;
}

function getUserIDFromUser(?string $user): int
{
    if (!$user) {
        return 0;
    }

    $query = "SELECT ID FROM UserAccounts WHERE User = :user";
    $row = legacyDbFetch($query, ['user' => $user]);

    return $row ? (int) $row['ID'] : 0;
}

function getUserMetadataFromID(int $userID): ?array
{
    $query = "SELECT * FROM UserAccounts WHERE ID ='$userID'";
    $dbResult = s_mysql_query($query);

    if ($dbResult !== false) {
        return mysqli_fetch_assoc($dbResult);
    }

    return null;
}

function validateUsername(string $userIn): ?string
{
    $user = User::firstWhere('User', $userIn);

    return ($user !== null) ? $user->User : null;
}

function getUserPageInfo(string $username, int $numGames = 0, int $numRecentAchievements = 0, bool $isAuthenticated = false): array
{
    $user = User::firstWhere('User', $username);
    if (!$user) {
        return [];
    }

    $libraryOut = [];

    $libraryOut['User'] = $user->User;
    $libraryOut['MemberSince'] = $user->Created?->__toString();
    $libraryOut['LastActivity'] = $user->LastLogin?->__toString();
    $libraryOut['LastActivityID'] = $user->LastActivityID;
    $libraryOut['RichPresenceMsg'] = empty($user->RichPresenceMsg) || $user->RichPresenceMsg === 'Unknown' ? null : $user->RichPresenceMsg;
    $libraryOut['LastGameID'] = (int) $user->LastGameID;
    $libraryOut['ContribCount'] = (int) $user->ContribCount;
    $libraryOut['ContribYield'] = (int) $user->ContribYield;
    $libraryOut['TotalPoints'] = (int) $user->RAPoints;
    $libraryOut['TotalSoftcorePoints'] = (int) $user->RASoftcorePoints;
    $libraryOut['TotalTruePoints'] = (int) $user->TrueRAPoints;
    $libraryOut['Permissions'] = (int) $user->getAttribute('Permissions');
    $libraryOut['Untracked'] = (int) $user->Untracked;
    $libraryOut['ID'] = (int) $user->ID;
    $libraryOut['UserWallActive'] = (int) $user->UserWallActive;
    $libraryOut['Motto'] = $user->Motto;

    $libraryOut['Rank'] = getUserRank($user->User);

    $recentlyPlayedData = [];
    $libraryOut['RecentlyPlayedCount'] = $isAuthenticated ? getRecentlyPlayedGames($user->User, 0, $numGames, $recentlyPlayedData) : 0;
    $libraryOut['RecentlyPlayed'] = $recentlyPlayedData;

    if ($libraryOut['RecentlyPlayedCount'] > 0) {
        $gameIDs = [];
        foreach ($recentlyPlayedData as $recentlyPlayed) {
            $gameIDs[] = $recentlyPlayed['GameID'];
        }

        if ($user->LastGameID && !in_array($user->LastGameID, $gameIDs)) {
            $gameIDs[] = $user->LastGameID;
        }

        $userProgress = getUserProgress($user, $gameIDs, $numRecentAchievements, withGameInfo: true);

        $libraryOut['Awarded'] = $userProgress['Awarded'];
        $libraryOut['RecentAchievements'] = $userProgress['RecentAchievements'];
        if (array_key_exists($user->LastGameID, $userProgress['GameInfo'])) {
            $libraryOut['LastGame'] = $userProgress['GameInfo'][$user->LastGameID];
        }
    }

    return $libraryOut;
}

function getUserListByPerms(int $sortBy, int $offset, int $count, ?array &$dataOut, ?string $requestedBy = null, int $perms = Permissions::Unregistered, bool $showUntracked = false): int
{
    $whereQuery = null;
    $permsFilter = null;

    if ($perms >= Permissions::Spam && $perms <= Permissions::Unregistered || $perms == Permissions::JuniorDeveloper) {
        $permsFilter = "ua.Permissions = $perms ";
    } elseif ($perms >= Permissions::Registered && $perms <= Permissions::Moderator) {
        $permsFilter = "ua.Permissions >= $perms ";
    } elseif ($showUntracked) {
        $whereQuery = "WHERE ua.Untracked ";
    } else {
        return 0;
    }

    if ($showUntracked) {
        if ($whereQuery == null) {
            $whereQuery = "WHERE $permsFilter ";
        }
    } else {
        $whereQuery = "WHERE ( NOT ua.Untracked || ua.User = \"$requestedBy\" ) AND $permsFilter";
    }

    $orderBy = match ($sortBy) {
        1 => "ua.User ASC ",
        11 => "ua.User DESC ",
        2 => "ua.RAPoints DESC ",
        12 => "ua.RAPoints ASC ",
        3 => "NumAwarded DESC ",
        13 => "NumAwarded ASC ",
        4 => "ua.LastLogin DESC ",
        14 => "ua.LastLogin ASC ",
        default => "ua.User ASC ",
    };

    $query = "SELECT ua.ID, ua.User, ua.RAPoints, ua.TrueRAPoints, ua.LastLogin,
                ua.achievements_unlocked NumAwarded
                FROM UserAccounts AS ua
                $whereQuery
                ORDER BY $orderBy
                LIMIT $offset, $count";

    $dataOut = legacyDbFetchAll($query)->toArray();

    return count($dataOut);
}

function GetDeveloperStatsFull(int $count, int $sortBy, int $devFilter = 7): array
{
    $stateCond = match ($devFilter) {
        // Active
        1 => " AND ua.Permissions >= " . Permissions::Developer,
        // Junior
        2 => " AND ua.Permissions = " . Permissions::JuniorDeveloper,
        // Active + Junior
        3 => " AND ua.Permissions >= " . Permissions::JuniorDeveloper,
        // Inactive
        4 => " AND ua.Permissions <= " . Permissions::Registered,
        // Active + Inactive
        5 => " AND ua.Permissions <> " . Permissions::JuniorDeveloper,
        // Junior + Inactive
        6 => " AND ua.Permissions <= " . Permissions::JuniorDeveloper,
        // Active + Junior + Inactive
        default => "",
    };

    $order = match ($sortBy) {
        // number of points allocated
        1 => "ContribYield DESC",
        // number of achievements won by others
        2 => "ContribCount DESC",
        3 => "OpenTickets DESC",
        4 => "TicketsResolvedForOthers DESC",
        5 => "LastLogin DESC",
        6 => "Author ASC",
        7 => "ActiveClaims DESC",
        default => "Achievements DESC",
    };

    $query = "
    SELECT
        ua.User AS Author,
        Permissions,
        ContribCount,
        ContribYield,
        COUNT(DISTINCT(IF(ach.Flags = 3, ach.ID, NULL))) AS Achievements,
        COUNT(DISTINCT(tick.ID)) AS OpenTickets,
        COALESCE(resolved.total,0) AS TicketsResolvedForOthers,
        LastLogin,
        COUNT(DISTINCT(sc.ID)) AS ActiveClaims
    FROM
        UserAccounts AS ua
    LEFT JOIN
        Achievements AS ach ON (ach.Author = ua.User AND ach.Flags IN (3, 5))
    LEFT JOIN
        Ticket AS tick ON (tick.AchievementID = ach.ID AND tick.ReportState IN (" . TicketState::Open . "," . TicketState::Request . "))
    LEFT JOIN
        SetClaim AS sc ON (sc.User = ua.User AND sc.Status IN (" . ClaimStatus::Active . ',' . ClaimStatus::InReview . "))
    LEFT JOIN (
        SELECT ua2.User,
        SUM(CASE WHEN t.ReportState = 2 THEN 1 ELSE 0 END) AS total
        FROM Ticket AS t
        LEFT JOIN UserAccounts as ua ON ua.ID = t.ReportedByUserID
        LEFT JOIN UserAccounts as ua2 ON ua2.ID = t.ResolvedByUserID
        LEFT JOIN Achievements as a ON a.ID = t.AchievementID
        WHERE ua.User NOT LIKE ua2.User
        AND a.Author NOT LIKE ua2.User
        AND a.Flags = '3'
        GROUP BY ua2.User) resolved ON resolved.User = ua.User
    WHERE
        ContribCount > 0 AND ContribYield > 0
        $stateCond
    GROUP BY
        ua.User
    ORDER BY
        $order,
        OpenTickets ASC";
    // LIMIT 0, $count";

    return legacyDbFetchAll($query)->toArray();
}

function GetUserFields(string $username, array $fields): ?array
{
    sanitize_sql_inputs($username);

    $fieldsCSV = implode(",", $fields);
    $query = "SELECT $fieldsCSV FROM UserAccounts AS ua
              WHERE ua.User = '$username'";
    $dbResult = s_mysql_query($query);

    if (!$dbResult) {
        return null;
    }

    return mysqli_fetch_assoc($dbResult);
}

/**
 * Gets completed and mastered counts for all users who have played the passed in games.
 */
function getMostAwardedUsers(array $gameIDs): array
{
    $retVal = [];
    if (empty($gameIDs)) {
        return $retVal;
    }

    $query = "SELECT ua.User,
              SUM(IF(AwardType LIKE " . AwardType::GameBeaten . " AND AwardDataExtra LIKE '0', 1, 0)) AS BeatenSoftcore,
              SUM(IF(AwardType LIKE " . AwardType::GameBeaten . " AND AwardDataExtra LIKE '1', 1, 0)) AS BeatenHardcore,
              SUM(IF(AwardType LIKE " . AwardType::Mastery . " AND AwardDataExtra LIKE '0', 1, 0)) AS Completed,
              SUM(IF(AwardType LIKE " . AwardType::Mastery . " AND AwardDataExtra LIKE '1', 1, 0)) AS Mastered
              FROM SiteAwards AS sa
              LEFT JOIN UserAccounts AS ua ON ua.User = sa.User
              WHERE sa.AwardType IN (" . implode(',', AwardType::game()) . ")
              AND AwardData IN (" . implode(",", $gameIDs) . ")
              AND Untracked = 0
              GROUP BY User
              ORDER BY User";

    $dbResult = s_mysql_query($query);
    if ($dbResult !== false) {
        while ($db_entry = mysqli_fetch_assoc($dbResult)) {
            $retVal[] = $db_entry;
        }
    }

    return $retVal;
}

/**
 * Gets completed and mastered counts for all the passed in games.
 */
function getMostAwardedGames(array $gameIDs): array
{
    $retVal = [];
    if (empty($gameIDs)) {
        return $retVal;
    }

    $query = "SELECT gd.Title, sa.AwardData AS ID, c.Name AS ConsoleName, gd.ImageIcon as GameIcon,
              SUM(IF(AwardType LIKE " . AwardType::GameBeaten . " AND AwardDataExtra LIKE '0' AND Untracked = 0, 1, 0)) AS BeatenSoftcore,
              SUM(IF(AwardType LIKE " . AwardType::GameBeaten . " AND AwardDataExtra LIKE '1' AND Untracked = 0, 1, 0)) AS BeatenHardcore,
              SUM(IF(AwardType LIKE " . AwardType::Mastery . " AND AwardDataExtra LIKE '0' AND Untracked = 0, 1, 0)) AS Completed,
              SUM(IF(AwardType LIKE " . AwardType::Mastery . " AND AwardDataExtra LIKE '1' AND Untracked = 0, 1, 0)) AS Mastered
              FROM SiteAwards AS sa
              LEFT JOIN GameData AS gd ON gd.ID = sa.AwardData
              LEFT JOIN Console AS c ON c.ID = gd.ConsoleID
              LEFT JOIN UserAccounts AS ua ON ua.User = sa.User
              WHERE sa.AwardType IN (" . implode(',', AwardType::game()) . ")
              AND AwardData IN(" . implode(",", $gameIDs) . ")
              GROUP BY sa.AwardData, gd.Title
              ORDER BY Title";

    $dbResult = s_mysql_query($query);
    if ($dbResult !== false) {
        while ($db_entry = mysqli_fetch_assoc($dbResult)) {
            $retVal[] = $db_entry;
        }
    }

    return $retVal;
}
