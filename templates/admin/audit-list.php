<?php
/**
 * Audit log.
 *
 * @package Formstead
 *
 * @var AuditTable $table List table for the current filter.
 * @var string     $event Selected event code, or an empty string.
 */

use Formstead\Admin\AuditTable;
use Formstead\Admin\Menu;
use Formstead\Audit\AuditEvent;

defined( 'ABSPATH' ) || exit;

$table = isset( $table ) && $table instanceof AuditTable ? $table : null;
$event = $table instanceof AuditTable ? $table->event() : '';
$from  = $table instanceof AuditTable ? $table->date_from() : '';
$to    = $table instanceof AuditTable ? $table->date_to() : '';
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Audit log', 'formstead' ); ?></h1>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( Menu::AUDIT_SLUG ); ?>">
		<label for="formstead-audit-event"><?php echo esc_html__( 'Event', 'formstead' ); ?></label>
		<select id="formstead-audit-event" name="event">
			<option value=""><?php echo esc_html__( 'All events', 'formstead' ); ?></option>
			<?php foreach ( AuditEvent::codes() as $code ) : ?>
				<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $event, $code ); ?>>
					<?php echo esc_html( AuditEvent::label( $code ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<label for="formstead-audit-from"><?php echo esc_html__( 'From', 'formstead' ); ?></label>
		<input type="date" id="formstead-audit-from" name="date_from" value="<?php echo esc_attr( $from ); ?>">
		<label for="formstead-audit-to"><?php echo esc_html__( 'To', 'formstead' ); ?></label>
		<input type="date" id="formstead-audit-to" name="date_to" value="<?php echo esc_attr( $to ); ?>">
		<?php submit_button( __( 'Filter', 'formstead' ), 'secondary', '', false ); ?>
	</form>

	<?php if ( $table instanceof AuditTable ) : ?>
		<?php $table->display(); ?>
	<?php endif; ?>
</div>
