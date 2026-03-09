<?php

if (!getenv('DOMAIN')) exit('ENV.DOMAIN undefined');

$cfg_domains = [

	getenv('DOMAIN') =>
	[
		'sql_host' =>                                       getenv('SQL_HOST'),
		'sql_user' =>                                       getenv('SQL_USER'),
		'sql_pass' =>                                       getenv('SQL_PASS'),
		'sql_base' =>                                       getenv('SQL_BASE'),
		'sql_debug' =>                                       getenv('APP_SQL_DEBUG'),
		
		'api_keys' => [
			'mailchimp' =>
			[
				'key' => getenv('MAILCHIMP_KEY'),
				'list_id' => getenv('MAILCHIMP_LIST_ID')
			],
			'google_recaptcha' =>
			[
				'public' => getenv('GOOGLE_RECAPTCHA_PUBLIC'),
				'private' => getenv('GOOGLE_RECAPTCHA_PRIVATE')
			]
		],
		'clients' =>
		[
			'debug' => getEnv('DEBUG'),
			'cache' => getEnv('CACHE'),
			'google' => [
				'oauth' =>
				[
					'client_id' => getenv('GOOGLE_OAUTH_CLIENT_ID'),
					'client_secret' => getenv('GOOGLE_OAUTH_CLIENT_SECRET'),
					'redirect_uri' => getenv('GOOGLE_OAUTH_REDIRECT_URI')
				],
				'analytics' => getenv('GOOGLE_ANALYTICS_TAG')
			],
			'facebook' => [
				'oauth' =>
				[
					'client_id' => getenv('FACEBOOK_OAUTH_CLIENT_ID'),
					'client_secret' => getenv('FACEBOOK_OAUTH_CLIENT_SECRET'),
					'redirect_uri' => getenv('FACEBOOK_OAUTH_REDIRECT_URI')
				]
			],
			'smtp' =>
			[
				'server' => getenv('SMTP_SERVER'),
				'port' => getenv('SMTP_PORT'),
				'secure' => getenv('SMTP_SECURE'),
				'login' => getenv('SMTP_LOGIN'),
				'pass' => getenv('SMTP_PASS'),
				'fromEmail' => getenv('SMTP_LOGIN'),
				'fromName' => getenv('SMTP_NAME')
			]
		]
	]
];

if (getenv('UPLOAD_SERVER')) {
	$cfg_domains[getenv('DOMAIN')]['upload_server'] = getenv('UPLOAD_SERVER');
}

if (getenv('S3_HOST')) {

	$cfg_domains[getenv('DOMAIN')]['s3'] = [

		'host' =>	getenv('S3_HOST'),
		'key' =>	getenv('S3_KEY'),
		'secret' =>	getenv('S3_SECRET'),
		'bucket' =>	getenv('S3_BUCKET'),
		'region' =>	getenv('S3_REGION'),
		'folder' =>	getenv('S3_FOLDER'),
		'cache' => '/cache/s3.files',
		'cache_sql' => false
	];
}
