<?php
/**
 * Public status page for a form submission.
 *
 * Callers set `$language`, `$title`, `$heading`, `$message`, and `$errors`
 * before including this file. Field values from the request are not available.
 *
 * @package Formstead
 *
 * @var string                                   $language Language tag for the document.
 * @var string                                   $title    Document title.
 * @var string                                   $heading  Visible heading.
 * @var string                                   $message  Paragraph used when there is no field list.
 * @var array<int, array{label: string, message: string}> $errors Field errors.
 */

defined( 'ABSPATH' ) || exit;

$language = isset( $language ) ? (string) $language : 'en';
$title    = isset( $title ) ? (string) $title : '';
$heading  = isset( $heading ) ? (string) $heading : '';
$message  = isset( $message ) ? (string) $message : '';
$errors   = isset( $errors ) && is_array( $errors ) ? $errors : array();
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $language ); ?>">
<head>
	<meta charset="utf-8">
	<title><?php echo esc_html( $title ); ?></title>
</head>
<body>
	<h1><?php echo esc_html( $heading ); ?></h1>
	<?php if ( array() !== $errors ) : ?>
		<ul>
			<?php foreach ( $errors as $formstead_error ) : ?>
				<?php
				$formstead_label   = is_array( $formstead_error ) && isset( $formstead_error['label'] ) ? (string) $formstead_error['label'] : '';
				$formstead_message = is_array( $formstead_error ) && isset( $formstead_error['message'] ) ? (string) $formstead_error['message'] : '';
				?>
				<li><?php echo esc_html( $formstead_label ); ?>: <?php echo esc_html( $formstead_message ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php elseif ( '' !== $message ) : ?>
		<p><?php echo esc_html( $message ); ?></p>
	<?php endif; ?>
</body>
</html>
