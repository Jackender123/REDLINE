<?php
/**
 * Redline - booking form helpers
 *
 *  rl_prefill_profile()       customer info from users (name, phone, zalo, email, contact method)
 *  rl_prefill_vehicles()      vehicles saved in the customer's garage (portal_vehicles)
 *  rl_render_vehicle_picker() the "use a saved vehicle" dropdown shown inside the booking forms
 *
 * File Location: www/src/includes/booking_prefill.php
 * Used by: ../services.php, ../performance.php, ../Fabrication.php
 */

if (!function_exists('e')) {
    function e($val) {
        return htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rl_prefill_profile')) {
    /**
     * Reads the customer straight from users (the session user may not carry
     * phone / zalo / contact_method). Returns [] for guests and staff.
     */
    function rl_prefill_profile(?array $user): array
    {
        if (!$user || empty($user['id'])) {
            return [];
        }

        $row = [];
        try {
            $s = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $s->execute(['id' => $user['id']]);
            $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $ex) {
            error_log('rl_prefill_profile: ' . $ex->getMessage());
        }

        $method = (string) ($row['contact_method'] ?? 'phone');

        return [
            'name'           => (string) ($row['full_name'] ?? $user['full_name'] ?? $user['name'] ?? ''),
            'phone'          => (string) ($row['phone'] ?? $user['phone'] ?? ''),
            'zalo'           => (string) ($row['zalo'] ?? ''),
            'email'          => (string) ($row['email'] ?? $user['email'] ?? ''),
            'contact_method' => in_array($method, ['phone', 'zalo', 'email'], true) ? $method : 'phone',
        ];
    }
}

if (!function_exists('rl_prefill_vehicles')) {
    /**
     * Vehicles saved in the customer's garage (../customer/index.php > My Vehicles).
     * SELECT * on purpose: mileage / color are added lazily by ../customer/index.php's schema upgrade.
     */
    function rl_prefill_vehicles(?array $user): array
    {
        if (!$user || empty($user['id'])) {
            return [];
        }

        try {
            $s = db()->prepare('SELECT * FROM portal_vehicles WHERE user_id = :uid ORDER BY id DESC');
            $s->execute(['uid' => $user['id']]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $ex) {
            error_log('rl_prefill_vehicles: ' . $ex->getMessage());
            return [];
        }

        return array_map(function ($v) {
            $year = (int) ($v['year'] ?? 0);
            return [
                'id'      => (int) $v['id'],
                'make'    => (string) ($v['make'] ?? ''),
                'model'   => (string) ($v['model'] ?? ''),
                'plate'   => (string) ($v['plate'] ?? ''),
                'year'    => $year > 0 ? (string) $year : '',
                'mileage' => isset($v['mileage']) ? (string) (int) $v['mileage'] : '',
                'color'   => (string) ($v['color'] ?? ''),
            ];
        }, $rows);
    }
}

if (!function_exists('rl_render_vehicle_picker')) {
    /**
     * Dropdown of saved vehicles. It has no "name" attribute, so it is never submitted;
     * public/assets/booking-prefill.js copies the chosen vehicle into the real form fields.
     */
    function rl_render_vehicle_picker(?array $user, array $vehicles): void
    {
        if (!$user) {
            return; // guests / staff: nothing to pick from
        }

        if (!$vehicles) { ?>
            <div class="sv-field form-group-full">
                <small style="opacity: .7;">You have no saved vehicles yet. <a href="<?= e(app_url('customer/index.php?page=vehicles')) ?>">Add one to your garage</a> and it will be available here next time.</small>
            </div>
            <?php
            return;
        }
        ?>
        <div class="sv-field form-group-full">
            <label for="f-saved-vehicle">USE A SAVED VEHICLE</label>
            <select id="f-saved-vehicle" data-saved-vehicle>
                <option value="">Enter a different vehicle</option>
                <?php foreach ($vehicles as $v):
                    $label = trim($v['make'] . ' ' . $v['model'] . ' ' . $v['year']) . ' · ' . $v['plate'];
                ?>
                    <option value="<?php echo (int) $v['id']; ?>" data-vehicle="<?php echo e(json_encode($v, JSON_UNESCAPED_UNICODE)); ?>"><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }
}
