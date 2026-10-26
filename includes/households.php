<?php
// Household creation, shared by registration and login.

// Creates a household for a user and links them to it as creator.
// Returns the new household id. Call inside a transaction if needed.
function ww_create_household($pdo, $user_id, $user_name, $size) {
    $size = max(1, min(12, (int) $size));
    $name = mb_substr($user_name, 0, 80) . "'s Household";

    $household_id = null;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO Households (name, invite_code, household_size, created_by) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute(array($name, $code, $size, $user_id));
            $household_id = (int) $pdo->lastInsertId();
            break;
        } catch (PDOException $e) {
            // 23000 = duplicate invite code. Try another code.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }
    if ($household_id === null) {
        throw new RuntimeException('Could not generate a unique invite code.');
    }

    $stmt = $pdo->prepare(
        "INSERT INTO HouseholdMembers (household_id, user_id, role) VALUES (?, ?, 'creator')"
    );
    $stmt->execute(array($household_id, $user_id));
    return $household_id;
}
