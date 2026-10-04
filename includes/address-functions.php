<?php

// ============================================================
// AURVIA ADDRESS FUNCTIONS
// ============================================================

require_once __DIR__ . "/functions.php";


function indianStates()
{
    return [
        'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh',
        'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka',
        'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram',
        'Nagaland', 'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu',
        'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
        'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu',
        'Delhi', 'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry',
    ];
}

function isValidPincode($pin)
{
    return is_string($pin) && preg_match('/^[1-9][0-9]{5}$/', $pin) === 1;
}

// Read + trim the address fields from a request array
function addressInput($source)
{
    return [
        'full_name'    => inputString($source, 'full_name'),
        'phone'        => inputString($source, 'phone'),
        'address_line1' => inputString($source, 'address_line1'),
        'address_line2' => inputString($source, 'address_line2'),
        'city'         => inputString($source, 'city'),
        'state'        => inputString($source, 'state'),
        'pincode'      => inputString($source, 'pincode'),
        'address_type' => inputString($source, 'address_type') ?: 'home',
        'is_default'   => !empty($source['is_default']) ? 1 : 0,
    ];
}

// returns [field => message]
function validateAddress($d)
{
    $errors = [];

    if (!isValidName($d['full_name'])) {
        $errors['full_name'] = 'Enter the receiver\'s name (2-100 letters).';
    }

    if (!isValidPhone($d['phone'])) {
        $errors['phone'] = 'Enter a valid 10-digit mobile number.';
    }

    $len1 = mb_strlen($d['address_line1']);
    if ($len1 < 5 || $len1 > 255) {
        $errors['address_line1'] = 'Enter house no. / building / street (5-255 characters).';
    }

    if (mb_strlen($d['address_line2']) > 255) {
        $errors['address_line2'] = 'Address line 2 is too long.';
    }

    if (preg_match("/^[\\p{L}][\\p{L} .'\\-]{1,99}$/u", $d['city']) !== 1) {
        $errors['city'] = 'Enter a valid city name.';
    }

    if (!in_array($d['state'], indianStates(), true)) {
        $errors['state'] = 'Select a state.';
    }

    if (!isValidPincode($d['pincode'])) {
        $errors['pincode'] = 'Enter a valid 6-digit pincode.';
    }

    if (!in_array($d['address_type'], ['home', 'work', 'other'], true)) {
        $errors['address_type'] = 'Choose home, work or other.';
    }

    return $errors;
}

function getAddresses($conn, $userId)
{
    $stmt = $conn->prepare("
        SELECT * FROM addresses
        WHERE user_id = ?
        ORDER BY is_default DESC, id DESC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

// only returns the address if it belongs to this user
function getAddress($conn, $userId, $addressId)
{
    $stmt = $conn->prepare("SELECT * FROM addresses WHERE id = ? AND user_id = ?");
    $addressId = (int)$addressId;
    $stmt->bind_param("ii", $addressId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function countAddresses($conn, $userId)
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM addresses WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)$row['total'];
}

// Create (id = null) or update an address.
// returns ['ok', 'errors' => [], 'id']
function saveAddress($conn, $userId, $data, $addressId = null)
{
    $errors = validateAddress($data);

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    $isNew = ($addressId === null);

    if ($isNew && countAddresses($conn, $userId) >= MAX_ADDRESSES) {
        return ['ok' => false, 'errors' => ['form' => 'You can save up to ' . MAX_ADDRESSES . ' addresses. Delete one first.']];
    }

    if (!$isNew && !getAddress($conn, $userId, $addressId)) {
        return ['ok' => false, 'errors' => ['form' => 'Address not found.']];
    }

    // the very first address is always the default
    $makeDefault = ($data['is_default'] == 1) || ($isNew && countAddresses($conn, $userId) === 0);
    $default = $makeDefault ? 1 : 0;

    $conn->begin_transaction();

    try {

        if ($makeDefault) {
            $stmt = $conn->prepare("UPDATE addresses SET is_default = 0 WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->close();
        }

        if ($isNew) {

            $stmt = $conn->prepare("
                INSERT INTO addresses
                    (user_id, full_name, phone, address_line1, address_line2,
                     city, state, pincode, address_type, is_default)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "issssssssi",
                $userId, $data['full_name'], $data['phone'], $data['address_line1'],
                $data['address_line2'], $data['city'], $data['state'], $data['pincode'],
                $data['address_type'], $default
            );
            $stmt->execute();
            $id = (int)$conn->insert_id;
            $stmt->close();

        } else {

            $id = (int)$addressId;

            // keep default flag if the user did not tick it
            $stmt = $conn->prepare("
                UPDATE addresses
                SET full_name = ?, phone = ?, address_line1 = ?, address_line2 = ?,
                    city = ?, state = ?, pincode = ?, address_type = ?,
                    is_default = IF(? = 1, 1, is_default)
                WHERE id = ? AND user_id = ?
            ");
            $stmt->bind_param(
                "ssssssssiii",
                $data['full_name'], $data['phone'], $data['address_line1'],
                $data['address_line2'], $data['city'], $data['state'], $data['pincode'],
                $data['address_type'], $default, $id, $userId
            );
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();

    } catch (Throwable $ex) {
        $conn->rollback();
        throw $ex;
    }

    return ['ok' => true, 'errors' => [], 'id' => $id];
}

function setDefaultAddress($conn, $userId, $addressId)
{
    if (!getAddress($conn, $userId, $addressId)) {
        return false;
    }

    $addressId = (int)$addressId;

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("UPDATE addresses SET is_default = 0 WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $addressId, $userId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Throwable $ex) {
        $conn->rollback();
        throw $ex;
    }

    return true;
}

function deleteAddress($conn, $userId, $addressId)
{
    $address = getAddress($conn, $userId, $addressId);

    if (!$address) {
        return false;
    }

    $addressId = (int)$addressId;

    $stmt = $conn->prepare("DELETE FROM addresses WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $addressId, $userId);
    $stmt->execute();
    $stmt->close();

    // deleted the default? promote the newest remaining address
    if ((int)$address['is_default'] === 1) {
        $stmt = $conn->prepare("
            UPDATE addresses SET is_default = 1
            WHERE user_id = ? ORDER BY id DESC LIMIT 1
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
    }

    return true;
}

// one-line text used as the snapshot stored with an order
function formatAddressText($a)
{
    $parts = [$a['address_line1']];

    if (!empty($a['address_line2'])) {
        $parts[] = $a['address_line2'];
    }

    $parts[] = $a['city'];
    $parts[] = $a['state'] . ' - ' . $a['pincode'];

    return implode(', ', $parts);
}


// ------------------------------------------------------------
// Shared form fields (checkout + address book)
// ------------------------------------------------------------

function renderAddressFields($v, $errors = [])
{
    $field = function ($name, $label, $extra = '') use ($v, $errors) {
        $invalid = isset($errors[$name]) ? 'is-invalid' : '';
        echo '<div class="col-md-6"><label class="form-label" for="f_' . $name . '">' . e($label) . '</label>'
            . '<input class="form-control ' . $invalid . '" id="f_' . $name . '" name="' . $name . '" '
            . 'value="' . e($v[$name] ?? '') . '" ' . $extra . '>'
            . '<div class="invalid-feedback">' . e($errors[$name] ?? '') . '</div></div>';
    };

    echo '<div class="row g-3">';

    $field('full_name', 'Full name', 'maxlength="100" required');
    $field('phone', 'Mobile number', 'maxlength="10" inputmode="numeric" required');

    echo '<div class="col-12"><label class="form-label" for="f_address_line1">Address (house no., street)</label>'
        . '<input class="form-control ' . (isset($errors['address_line1']) ? 'is-invalid' : '') . '" id="f_address_line1" name="address_line1" maxlength="255" required value="' . e($v['address_line1'] ?? '') . '">'
        . '<div class="invalid-feedback">' . e($errors['address_line1'] ?? '') . '</div></div>';

    echo '<div class="col-12"><label class="form-label" for="f_address_line2">Landmark / area (optional)</label>'
        . '<input class="form-control ' . (isset($errors['address_line2']) ? 'is-invalid' : '') . '" id="f_address_line2" name="address_line2" maxlength="255" value="' . e($v['address_line2'] ?? '') . '">'
        . '<div class="invalid-feedback">' . e($errors['address_line2'] ?? '') . '</div></div>';

    $field('city', 'City', 'maxlength="100" required');

    echo '<div class="col-md-6"><label class="form-label" for="f_state">State</label>'
        . '<select class="form-select ' . (isset($errors['state']) ? 'is-invalid' : '') . '" id="f_state" name="state" required>'
        . '<option value="">Select state</option>';
    foreach (indianStates() as $st) {
        echo '<option value="' . e($st) . '"' . (($v['state'] ?? '') === $st ? ' selected' : '') . '>' . e($st) . '</option>';
    }
    echo '</select><div class="invalid-feedback">' . e($errors['state'] ?? '') . '</div></div>';

    $field('pincode', 'Pincode', 'maxlength="6" inputmode="numeric" required');

    echo '<div class="col-md-6"><label class="form-label" for="f_address_type">Address type</label>'
        . '<select class="form-select" id="f_address_type" name="address_type">';
    foreach (['home' => 'Home', 'work' => 'Work', 'other' => 'Other'] as $val => $label) {
        echo '<option value="' . $val . '"' . (($v['address_type'] ?? 'home') === $val ? ' selected' : '') . '>' . $label . '</option>';
    }
    echo '</select></div>';

    echo '<div class="col-12"><div class="form-check">'
        . '<input class="form-check-input" type="checkbox" name="is_default" value="1" id="f_is_default"' . (!empty($v['is_default']) ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="f_is_default">Make this my default address</label></div></div>';

    echo '</div>';
}

?>
