<?php
$path = dirname(__DIR__, 2) . '/www/admin/orders/create.php';
$source = file_get_contents($path);
$baseline = shell_exec('git show 0f352be:www/repair_orders_create.php');
$start = '        <!-- Form content -->';
$end = '    <!-- JAVASCRIPT LOGIC -->';
$form = substr($baseline, strpos($baseline, $start), strpos($baseline, $end) - strpos($baseline, $start));
$form = str_replace(['$appointmentId', 'name="appointment_id"'], ['$serviceRequestId', 'name="service_request_id"'], $form);
$warningStart = strpos($source, '            <?php if ($requestWarning');
$warningEnd = strpos($source, '            <div class="grid grid-cols-1 lg:grid-cols-3', $warningStart);
$warnings = substr($source, $warningStart, $warningEnd - $warningStart);
$grid = '            <div class="grid grid-cols-1 lg:grid-cols-3';
$form = str_replace($grid, $warnings . $grid, $form);
$catalog = <<<'HTML'
<select name="service_type" class="glass-input w-full px-3.5 py-2.5 text-xs font-semibold">
                                    <?php if ($selectedServiceType !== '' && !isset(rl_service_catalog()[$selectedServiceType])): ?>
                                        <option value="<?= e($selectedServiceType) ?>" selected><?= e($selectedServiceType) ?></option>
                                    <?php endif; ?>
                                    <?php foreach (rl_service_groups() as $group => $options): ?>
                                        <optgroup label="<?= e($group) ?>">
                                            <?php foreach ($options as $value => $label): ?>
                                                <option value="<?= e($value) ?>" <?= $selectedServiceType === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
HTML;
$form = preg_replace('/<select name="service_type".*?<\/select>/s', $catalog, $form, 1, $count);
if ($count !== 1) throw new RuntimeException('Could not locate service selector.');
$source = substr($source, 0, strpos($source, $start)) . $form . substr($source, strpos($source, $end));
$source = str_replace("evSelector.value === '1'", "['ev', 'hybrid'].includes(evSelector.value)", $source);
file_put_contents($path, $source);
