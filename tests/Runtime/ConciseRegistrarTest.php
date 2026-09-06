<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;

final class ConciseRegistrarTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp/concise-registrar-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root . '/plugin', 0700, true );
		mkdir( $this->root . '/theme', 0700, true );
		mkdir( $this->root . '/manager-theme', 0700, true );
		mkdir( $this->root . '/forced-plugin', 0700, true );
		mkdir( $this->root . '/late-plugin', 0700, true );
		file_put_contents( $this->root . '/plugin/plugin.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n*/\n" );
		file_put_contents( $this->root . '/theme/style.css', "/*\nTheme Name: Example Theme\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example-theme\n*/\n" );
		file_put_contents( $this->root . '/manager-theme/style.css', "/*\nTheme Name: Managed Theme\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/managed-theme\n*/\n" );
		file_put_contents( $this->root . '/forced-plugin/main.php', "<?php\n/*\nPlugin Name: Forced Plugin\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/forced-plugin\n*/\n" );
		file_put_contents( $this->root . '/late-plugin/main.php', "<?php\n/*\nPlugin Name: Late Plugin\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/late-plugin\n*/\n" );
		mkdir( $this->root . '/canonical', 0700, true );
		file_put_contents( $this->root . '/canonical/plugin.php', "<?php\n/*\nPlugin Name: Canonical\nVersion: 1.0.0\nUpdate URI: HTTPS://GitHub.com/acme/example/\n*/\n" );
		mkdir( $this->root . '/mismatch', 0700, true );
		file_put_contents( $this->root . '/mismatch/plugin.php', "<?php\n/*\nPlugin Name: Mismatch\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/other\n*/\n" );
	}

	public function testPluginThemeAndManagedThemeDeclarationsActivateWithoutResolvingCredentials(): void
	{
		$result = $this->probe( <<<'PHP'
$calls=0;$registrar=require $data['bootstrap'];$plugin=$registrar->plugin('github',$data['plugin'],'acme/example','123456789','stable','manual',static function()use(&$calls){++$calls;return 'secret';});$theme=$registrar->theme('github',$data['theme'],'acme/example-theme','234567890','prerelease','automatic');$manager=$registrar->theme('github',$data['manager'],'acme/managed-theme','345678901','stable','disabled');$forced=$registrar->plugin('github',$data['forced'],'acme/forced-plugin','456789012','stable','forced-off',static function()use(&$calls){++$calls;return 'secret';});$plugin->register();$theme->register();$manager->register();$forced->register();$activated=$GLOBALS['ran_wp_release_updater_v1_broker']->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);echo json_encode(['activation'=>$activated['code'],'plugin'=>$plugin->status()['code'],'theme'=>$theme->status()['code'],'manager'=>$manager->status()['code'],'forced'=>$forced->status()['code'],'credentials'=>$calls]);
PHP );
		self::assertSame( 'runtime_active', $result['activation'] );
		self::assertSame( 'target_active', $result['plugin'] );
		self::assertSame( 'target_active', $result['theme'] );
		self::assertSame( 'target_active', $result['manager'] );
		self::assertSame( 'target_active', $result['forced'] );
		self::assertSame( 0, $result['credentials'] );
	}

	public function testInvalidAndUnsupportedDeclarationsStayPassive(): void
	{
		$result = $this->probe( <<<'PHP'
$registrar=require $data['bootstrap'];$unsupported=$registrar->plugin('gitlab',$data['plugin'],'acme/example','123456789');$missing=$registrar->plugin('github',$data['root'].'/missing.php','acme/example','123456789');$missingUnsupported=$registrar->plugin('gitlab',$data['root'].'/missing.php','acme/example','123456789');$invalid=$registrar->plugin('github',$data['plugin'],'bad locator','abc','stable','nope');$unsupported->register();$missing->register();$missingUnsupported->register();$invalidResult=$invalid->register();$GLOBALS['ran_wp_release_updater_v1_broker']->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);echo json_encode(['unsupported'=>$unsupported->status()['code'],'missing'=>$missing->status()['code'],'missingUnsupported'=>$missingUnsupported->status()['code'],'invalid'=>$invalidResult,'hooks'=>count($GLOBALS['p0_1_hooks'])]);
PHP );
		self::assertSame( 'unsupported_provider', $result['unsupported'] );
		self::assertSame( 'installed_file_missing', $result['missing'] );
		self::assertSame( 'installed_file_missing', $result['missingUnsupported'] );
		self::assertFalse( $result['invalid'] );
		self::assertSame( 0, $result['hooks'] );
	}

	public function testArtifactLimitIsTargetLocalAndInvalidValuesStayPassive(): void
	{
		$result = $this->probe( <<<'PHP'
$r=require $data['bootstrap'];$first=$r->plugin('github',$data['plugin'],'acme/example','123456789','stable','manual',null,83886080);$duplicate=$r->plugin('github',$data['plugin'],'acme/example','123456789','stable','manual',null,83886080);$conflict=$r->plugin('github',$data['plugin'],'acme/example','123456789','stable','manual',null,104857600);$invalid=$r->plugin('github',$data['forced'],'acme/forced-plugin','456789012','stable','manual',null,0);$numeric=$r->plugin('github',$data['forced'],'acme/forced-plugin','456789012','stable','manual',null,'83886080');$fractional=$r->plugin('github',$data['forced'],'acme/forced-plugin','456789012','stable','manual',null,83886080.5);$boolean=$r->plugin('github',$data['forced'],'acme/forced-plugin','456789012','stable','manual',null,true);$first->register();$duplicate->register();$conflict->register();$invalid->register();$numeric->register();$fractional->register();$boolean->register();$GLOBALS['ran_wp_release_updater_v1_broker']->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);echo json_encode(['first'=>$first->status()['code'],'duplicate'=>$duplicate->status()['code'],'conflict'=>$conflict->status()['code'],'invalid'=>$invalid->status()['code'],'invalid_diagnostics'=>$invalid->diagnostics(),'numeric'=>$numeric->status()['code'],'fractional'=>$fractional->status()['code'],'boolean'=>$boolean->status()['code'],'logical'=>$GLOBALS['ran_wp_release_updater_v1_broker']->diagnostics()['logical_target_count']]);
PHP );

		self::assertSame( 'target_active', $result['first'] );
		self::assertSame( 'target_active', $result['duplicate'] );
		self::assertSame( 'target_declaration_conflict', $result['conflict'] );
		self::assertSame( 'maximum_artifact_bytes_invalid', $result['invalid'] );
		self::assertSame( array( 'state' => 'inactive', 'diagnostics' => array( array( 'code' => 'maximum_artifact_bytes_invalid' ) ) ), $result['invalid_diagnostics'] );
		self::assertSame( 'maximum_artifact_bytes_invalid', $result['numeric'] );
		self::assertSame( 'maximum_artifact_bytes_invalid', $result['fractional'] );
		self::assertSame( 'maximum_artifact_bytes_invalid', $result['boolean'] );
		self::assertSame( 1, $result['logical'] );
	}

	public function testCanonicalDuplicateSharesOneTargetAndConflictLeavesItAuthoritative(): void
	{
		$result = $this->probe( <<<'PHP'
$calls=0;$credentials=static function()use(&$calls){++$calls;return 'secret';};$registrar=require $data['bootstrap'];$first=$registrar->plugin('github',$data['plugin'],'acme/example','123456789','stable','manual',$credentials);$duplicate=$registrar->plugin('github',$data['plugin'],'acme/example','123456789','stable','manual',$credentials);$conflict=$registrar->plugin('gitlab',$data['plugin'],'acme/example','123456789','stable','manual');$first->register();$duplicate->register();$conflict->register();$broker=$GLOBALS['ran_wp_release_updater_v1_broker'];$broker->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);echo json_encode(['first'=>$first->status(),'duplicate'=>$duplicate->status(),'conflict'=>$conflict->status(),'logical'=>$broker->diagnostics()['logical_target_count'],'hooks'=>count($GLOBALS['p0_1_hooks']),'calls'=>$calls]);
PHP );
		self::assertSame( 'target_active', $result['first']['code'] );
		self::assertSame( 'target_active', $result['duplicate']['code'] );
		self::assertSame( 'target_declaration_conflict', $result['conflict']['code'] );
		self::assertSame( 1, $result['logical'] );
		self::assertSame( 10, $result['hooks'] );
		self::assertSame( 0, $result['calls'] );
	}

	public function testOperationCutoffDefersOnlyNewSameTypeTargetsAndKeepsTheOtherTypeOpen(): void
	{
		$result = $this->probe( <<<'PHP'
$calls=0;$resolver=static function()use(&$calls){++$calls;return 'secret';};$r=require $data['bootstrap'];$before=$r->plugin('github',$data['plugin'],'acme/example','123456789','stable','manual',$resolver);$before->register();$broker=$GLOBALS['ran_wp_release_updater_v1_broker'];$broker->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);foreach($GLOBALS['p0_1_hooks'] as $registered){if('upgrader_package_options'===$registered['hook']){($registered['callback'])(['hook_extra'=>['plugin'=>'plugin/plugin.php','action'=>'update','type'=>'plugin']]);}}$during=$r->plugin('github',$data['forced'],'acme/forced-plugin','456789012','stable','manual',$resolver);$duplicate=$r->plugin('github',$data['forced'],'acme/forced-plugin','456789012','stable','manual',$resolver);$conflict=$r->plugin('github',$data['forced'],'acme/forced-plugin','456789012','stable','automatic',$resolver);$theme=$r->theme('github',$data['manager'],'acme/managed-theme','345678901','stable','manual',$resolver);$during->register();$duplicate->register();$conflict->register();$theme->register();echo json_encode(['before'=>$before->status(),'during'=>$during->status(),'duplicate'=>$duplicate->status(),'conflict'=>$conflict->status(),'theme'=>$theme->status(),'deferred_refresh'=>$during->refresh(),'logical'=>$broker->diagnostics()['logical_target_count'],'hooks'=>count($GLOBALS['p0_1_hooks']),'calls'=>$calls,'during_diagnostics'=>$during->diagnostics()]);
PHP );

		self::assertSame( 'target_active', $result['before']['code'] );
		self::assertSame( 'declaration_deferred_operation_started', $result['during']['code'] );
		self::assertSame( 'deferred', $result['during']['state'] );
		self::assertFalse( $result['during']['hooks_registered'] );
		self::assertSame( 'declaration_deferred_operation_started', $result['duplicate']['code'] );
		self::assertSame( 'target_declaration_conflict', $result['conflict']['code'] );
		self::assertSame( 'target_active', $result['theme']['code'] );
		self::assertFalse( $result['deferred_refresh'] );
		self::assertSame( 3, $result['logical'] );
		self::assertSame( 19, $result['hooks'] );
		self::assertSame( 0, $result['calls'] );
		self::assertSame(
			array( 'state' => 'deferred', 'diagnostics' => array( array( 'code' => 'declaration_deferred_operation_started' ) ) ),
			$result['during_diagnostics']
		);
	}

	public function testC01TwoPreAdmittedPluginsContinueThroughTheSameTypeCutoff(): void
	{
		$result = $this->probe( <<<'PHP'
$r=require $data['bootstrap'];$first=$r->plugin('github',$data['plugin'],'acme/example','123456789','stable','disabled');$second=$r->plugin('github',$data['forced'],'acme/forced-plugin','456789012','stable','disabled');$first->register();$second->register();$broker=$GLOBALS['ran_wp_release_updater_v1_broker'];$broker->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);foreach($GLOBALS['p0_1_hooks'] as $registered){if('update_plugins_github.com'===$registered['hook']){($registered['callback'])(false,['Version'=>'1.0.0','UpdateURI'=>'https://github.com/acme/example'],'plugin/plugin.php',[]);($registered['callback'])(false,['Version'=>'1.0.0','UpdateURI'=>'https://github.com/acme/forced-plugin'],'forced-plugin/main.php',[]);}}$late=$r->plugin('github',$data['late'],'acme/late-plugin','567890123','stable','disabled');$late->register();echo json_encode(['first'=>$first->status(),'second'=>$second->status(),'late'=>$late->status(),'hooks'=>count($GLOBALS['p0_1_hooks']),'logical'=>$broker->diagnostics()['logical_target_count']]);
PHP );

		self::assertSame( 'target_active', $result['first']['code'] );
		self::assertSame( 'target_active', $result['second']['code'] );
		self::assertSame( 'declaration_deferred_operation_started', $result['late']['code'] );
		self::assertSame( 20, $result['hooks'] );
		self::assertSame( 3, $result['logical'] );
	}

	public function testC02DeclarationReenteredByTheFirstTargetFilterIsDeferredAndDoesNotJoinLaterCallbacks(): void
	{
		$result = $this->probe( <<<'PHP'
$r=require $data['bootstrap'];$first=$r->plugin('github',$data['plugin'],'acme/example','123456789','stable','disabled');$first->register();$broker=$GLOBALS['ran_wp_release_updater_v1_broker'];$broker->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);$late=null;$runs=0;foreach($GLOBALS['p0_1_hooks'] as $registered){if('update_plugins_github.com'===$registered['hook']){($registered['callback'])(false,['Version'=>'1.0.0','UpdateURI'=>'https://github.com/acme/example'],'plugin/plugin.php',[]);$late=$r->plugin('github',$data['late'],'acme/late-plugin','567890123','stable','disabled');$late->register();($registered['callback'])(false,['Version'=>'1.0.0','UpdateURI'=>'https://github.com/acme/example'],'plugin/plugin.php',[]);++$runs;}}echo json_encode(['first'=>$first->status(),'late'=>$late->status(),'late_diagnostics'=>$late->diagnostics(),'runs'=>$runs,'hooks'=>count($GLOBALS['p0_1_hooks'])]);
PHP );

		self::assertSame( 'target_active', $result['first']['code'] );
		self::assertSame( 'declaration_deferred_operation_started', $result['late']['code'] );
		self::assertSame( 'deferred', $result['late']['state'] );
		self::assertFalse( $result['late']['hooks_registered'] );
		self::assertSame( array( 'state' => 'deferred', 'diagnostics' => array( array( 'code' => 'declaration_deferred_operation_started' ) ) ), $result['late_diagnostics'] );
		self::assertSame( 1, $result['runs'] );
		self::assertSame( 10, $result['hooks'] );
	}

	public function testC03DeclarationAfterTheInstallerCutoffIsDeferredEvenWhenCoreFailsEarly(): void
	{
		$result = $this->probe( <<<'PHP'
$r=require $data['bootstrap'];$first=$r->plugin('github',$data['plugin'],'acme/example','123456789','stable','disabled');$first->register();$broker=$GLOBALS['ran_wp_release_updater_v1_broker'];$broker->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);foreach($GLOBALS['p0_1_hooks'] as $registered){if('upgrader_package_options'===$registered['hook']){($registered['callback'])(['hook_extra'=>['plugin'=>'plugin/plugin.php','action'=>'update','type'=>'plugin']]);}}$late=$r->plugin('github',$data['late'],'acme/late-plugin','567890123','stable','disabled');$late->register();echo json_encode(['late'=>$late->status(),'diagnostics'=>$late->diagnostics(),'hooks'=>count($GLOBALS['p0_1_hooks'])]);
PHP );

		self::assertSame( 'declaration_deferred_operation_started', $result['late']['code'] );
		self::assertFalse( $result['late']['hooks_registered'] );
		self::assertSame( array( 'state' => 'deferred', 'diagnostics' => array( array( 'code' => 'declaration_deferred_operation_started' ) ) ), $result['diagnostics'] );
		self::assertSame( 10, $result['hooks'] );
	}

	public function testC04FreshRequestDeclarationIsNotDeferredByAPriorRequestCutoff(): void
	{
		$first = $this->probe( <<<'PHP'
$r=require $data['bootstrap'];$h=$r->plugin('github',$data['plugin'],'acme/example','123456789','stable','disabled');$h->register();$GLOBALS['ran_wp_release_updater_v1_broker']->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);foreach($GLOBALS['p0_1_hooks'] as $registered){if('update_plugins_github.com'===$registered['hook']){($registered['callback'])(false,['Version'=>'1.0.0','UpdateURI'=>'https://github.com/acme/example'],'plugin/plugin.php',[]);}}echo json_encode($h->status());
PHP );
		$fresh = $this->probe( <<<'PHP'
$r=require $data['bootstrap'];$h=$r->plugin('github',$data['late'],'acme/late-plugin','567890123','stable','disabled');$h->register();$GLOBALS['ran_wp_release_updater_v1_broker']->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);echo json_encode($h->status());
PHP );

		self::assertSame( 'target_active', $first['code'] );
		self::assertSame( 'target_active', $fresh['code'] );
		self::assertTrue( $fresh['hooks_registered'] );
	}

	public function testCanonicalEquivalentInstalledUriActivatesAndMismatchHasExactCode(): void
	{
		$result = $this->probe( <<<'PHP'
$r=require $data['bootstrap'];$equal=$r->plugin('github',$data['canonical'],'acme/example','123456789');$different=$r->plugin('github',$data['mismatch'],'acme/example','123456789');$equal->register();$different->register();$GLOBALS['ran_wp_release_updater_v1_broker']->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);echo json_encode(['equal'=>$equal->status()['code'],'different'=>$different->status()['code']]);
PHP );
		self::assertSame( 'target_active', $result['equal'] );
		self::assertSame( 'installed_update_uri_mismatch', $result['different'] );
	}

	public function testDotRepositoryNamesAreRejectedBeforeComposition(): void
	{
		$result = $this->probe( <<<'PHP'
$r=require $data['bootstrap'];$dot=$r->plugin('github',$data['plugin'],'acme/.','123456789');$dotdot=$r->plugin('github',$data['plugin'],'acme/..','123456789');$dot->register();$dotdot->register();$GLOBALS['ran_wp_release_updater_v1_broker']->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);echo json_encode(['dot'=>$dot->status()['code'],'dotdot'=>$dotdot->status()['code'],'hooks'=>count($GLOBALS['p0_1_hooks'])]);
PHP );
		self::assertSame( 'repository_locator_invalid', $result['dot'] );
		self::assertSame( 'repository_locator_invalid', $result['dotdot'] );
		self::assertSame( 0, $result['hooks'] );
	}

	public function testEveryDeclarationFactAndResolverIdentityConflictsWithoutInvocation(): void
	{
		$result = $this->probe( <<<'PHP'
$calls=0;$firstResolver=static function()use(&$calls){++$calls;return 'first';};$otherResolver=static function()use(&$calls){++$calls;return 'other';};$r=require $data['bootstrap'];$first=$r->plugin('github',$data['plugin'],'acme/example','123456789','stable','manual',$firstResolver);$first->register();$conflicts=[];foreach([['gitlab','acme/example','123456789','stable','manual',$firstResolver],['github','acme/other','123456789','stable','manual',$firstResolver],['github','acme/example','987654321','stable','manual',$firstResolver],['github','acme/example','123456789','prerelease','manual',$firstResolver],['github','acme/example','123456789','stable','automatic',$firstResolver],['github','acme/example','123456789','stable','manual',$otherResolver]] as $facts){$h=$r->plugin($facts[0],$data['plugin'],$facts[1],$facts[2],$facts[3],$facts[4],$facts[5]);$h->register();$conflicts[]=$h;}$broker=$GLOBALS['ran_wp_release_updater_v1_broker'];$activation=$broker->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);echo json_encode(['activation'=>$activation['code'],'first'=>$first->status()['code'],'conflicts'=>array_map(static fn($h)=>$h->status()['code'],$conflicts),'logical'=>$broker->diagnostics()['logical_target_count'],'hooks'=>count($GLOBALS['p0_1_hooks']),'calls'=>$calls]);
PHP );

		self::assertSame( 'runtime_active', $result['activation'] );
		self::assertSame( 'target_active', $result['first'] );
		self::assertSame( array_fill( 0, 6, 'target_declaration_conflict' ), $result['conflicts'] );
		self::assertSame( 1, $result['logical'] );
		self::assertSame( 10, $result['hooks'] );
		self::assertSame( 0, $result['calls'] );
	}

	public function testResolverIdentityMatrixIsLazyAndUsesCallableIdentityRatherThanBehaviour(): void
	{
		$result = $this->probe( <<<'PHP'
final class P01InvokableResolver { public function __construct(private int &$calls) {} public function __invoke(): string { ++$this->calls; return 'secret'; } }
final class P01StaticResolver { public static int $calls = 0; public static function resolve(): string { ++self::$calls; return 'secret'; } }
final class P01ArrayResolver { public int $calls = 0; public function resolve(): string { ++$this->calls; return 'secret'; } }
$calls=0;$same=new P01InvokableResolver($calls);$different=new P01InvokableResolver($calls);$array=new P01ArrayResolver();$static=[P01StaticResolver::class,'resolve'];$staticString=P01StaticResolver::class.'::resolve';$freshA=static function()use(&$calls):string{++$calls;return 'same';};$freshB=static function()use(&$calls):string{++$calls;return 'same';};foreach(['null','invokable','static','static-string','array','fresh'] as $name){mkdir($data['root'].'/'.$name,0700,true);file_put_contents($data['root'].'/'.$name.'/main.php',"<?php\n/*\nPlugin Name: {$name}\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n*/\n");}$r=require $data['bootstrap'];$handles=[];foreach([['null',null],['null',null],['invokable',$same],['invokable',$same],['invokable',$different],['static',$static],['static',$static],['static-string',$staticString],['static-string',$staticString],['array',[$array,'resolve']],['array',[$array,'resolve']],['fresh',$freshA],['fresh',$freshB]] as [$name,$resolver]){$h=$r->plugin('github',$data['root'].'/'.$name.'/main.php','acme/example','123456789','stable','manual',$resolver);$h->register();$handles[]=$h;}$broker=$GLOBALS['ran_wp_release_updater_v1_broker'];$activation=$broker->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);echo json_encode(['activation'=>$activation['code'],'codes'=>array_map(static fn($h)=>$h->status()['code'],$handles),'logical'=>$broker->diagnostics()['logical_target_count'],'calls'=>$calls,'static'=>P01StaticResolver::$calls,'array'=>$array->calls,'hooks'=>count($GLOBALS['p0_1_hooks'])]);
PHP );

		self::assertSame( 'runtime_active', $result['activation'] );
		self::assertSame( array( 'target_active', 'target_active', 'target_active', 'target_active', 'target_declaration_conflict', 'target_active', 'target_active', 'target_active', 'target_active', 'target_active', 'target_active', 'target_active', 'target_declaration_conflict' ), $result['codes'] );
		self::assertSame( 6, $result['logical'] );
		self::assertSame( 0, $result['calls'] );
		self::assertSame( 0, $result['static'] );
		self::assertSame( 0, $result['array'] );
		self::assertSame( 60, $result['hooks'] );
	}

	public function testNetworkCallbackMatrixIsReadOnceAndDoesNotUseBlogIdentity(): void
	{
		foreach ( array( 'absent' => null, 'one' => 1, 'true' => true, 'false' => false, 'zero' => 0, 'negative' => -1, 'string' => '1', 'throw' => 'throw' ) as $name => $network ) {
			$result = $this->probe( <<<'PHP'
$calls=0;if (null !== $data['network']) { function get_current_network_id(): mixed { ++$GLOBALS['p0_network_calls']; if ('throw' === $GLOBALS['p0_network_value']) throw new RuntimeException('network'); return $GLOBALS['p0_network_value']; } } function get_current_blog_id(): int { return 999; } $GLOBALS['p0_network_calls']=0;$GLOBALS['p0_network_value']=$data['network'];$r=require $data['bootstrap'];$h=$r->plugin('github',$data['plugin'],'acme/example','123456789');$h->register();$activation=$GLOBALS['ran_wp_release_updater_v1_broker']->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);$h->status();echo json_encode(['activation'=>$activation['code'],'target'=>$h->status()['code'],'calls'=>$GLOBALS['p0_network_calls'],'hooks'=>count($GLOBALS['p0_1_hooks'])]);
PHP, array( 'network' => $network ) );
			self::assertSame( 'runtime_active', $result['activation'], $name );
			self::assertSame( in_array( $name, array( 'absent', 'one' ), true ) ? 'target_active' : 'runtime_environment_invalid', $result['target'], $name );
			self::assertSame( 'absent' === $name ? 0 : 1, $result['calls'], $name );
			self::assertSame( in_array( $name, array( 'absent', 'one' ), true ) ? 10 : 0, $result['hooks'], $name );
		}
	}

	public function testLogicalAndRealInstalledAliasesShareOneTargetHandleAndHookSet(): void
	{
		$result = $this->probe( <<<'PHP'
$actual=$data['root'].'-actual/alias';$logical=$data['root'].'/alias';mkdir($actual,0700,true);file_put_contents($actual.'/main.php',"<?php\n/*\nPlugin Name: Alias\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n*/\n");symlink($actual,$logical);$GLOBALS['wp_plugin_paths']=[$logical=>$actual];$r=require $data['bootstrap'];$first=$r->plugin('github',$logical.'/main.php','acme/example','123456789');$second=$r->plugin('github',$actual.'/main.php','acme/example','123456789');$first->register();$second->register();$broker=$GLOBALS['ran_wp_release_updater_v1_broker'];$broker->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);$targets=(new ReflectionProperty($broker,'targetHandles'))->getValue($broker);echo json_encode(['first'=>$first->status()['code'],'second'=>$second->status()['code'],'logical'=>$broker->diagnostics()['logical_target_count'],'hooks'=>count($GLOBALS['p0_1_hooks']),'one_handle'=>1===count($targets)]);
PHP );

		self::assertSame( 'target_active', $result['first'] );
		self::assertSame( 'target_active', $result['second'] );
		self::assertSame( 1, $result['logical'] );
		self::assertSame( 10, $result['hooks'] );
		self::assertTrue( $result['one_handle'] );
	}

	public function testRejectedDeclarationDoesNotReserveItsCanonicalTarget(): void
	{
		$result = $this->probe( <<<'PHP'
$calls=0;$resolver=static function()use(&$calls){++$calls;return 'secret';};$r=require $data['bootstrap'];$rejected=$r->plugin('github',$data['plugin'],'not a locator','123456789','stable','manual',$resolver);$accepted=$r->plugin('github',$data['plugin'],'acme/example','123456789','stable','manual',$resolver);$rejected->register();$accepted->register();$broker=$GLOBALS['ran_wp_release_updater_v1_broker'];$broker->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);echo json_encode(['rejected'=>$rejected->status()['code'],'accepted'=>$accepted->status()['code'],'logical'=>$broker->diagnostics()['logical_target_count'],'hooks'=>count($GLOBALS['p0_1_hooks']),'calls'=>$calls]);
PHP );

		self::assertSame( 'repository_locator_invalid', $result['rejected'] );
		self::assertSame( 'target_active', $result['accepted'] );
		self::assertSame( 1, $result['logical'] );
		self::assertSame( 10, $result['hooks'] );
		self::assertSame( 0, $result['calls'] );
	}

	public function testSelectedHandleRefreshFailsClosedWhenTheBrokerIdentityChanges(): void
	{
		$result = $this->probe( <<<'PHP'
$registrar=require $data['bootstrap'];$plugin=$registrar->plugin('github',$data['plugin'],'acme/example','123456789');$plugin->register();$GLOBALS['ran_wp_release_updater_v1_broker']->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);$GLOBALS['ran_wp_release_updater_v1_broker']=new stdClass();echo json_encode(['refresh'=>$plugin->refresh(),'status'=>$plugin->status(),'diagnostics'=>$plugin->diagnostics()]);
PHP );
		self::assertFalse( $result['refresh'] );
		self::assertSame( 'protocol_conflict_inactive', $result['status']['code'] );
		self::assertTrue( $result['status']['hooks_registered'] );
		self::assertSame( array( array( 'code' => 'protocol_conflict_inactive' ) ), $result['diagnostics']['diagnostics'] );
	}

	public function testEveryNativeCallbackAndFinalizerArePassiveAfterEachProtocolConflict(): void
	{
		foreach ( array( 'stale_global', 'wrong_protocol', 'legacy_broker', 'legacy_marker' ) as $fault ) {
			$result = $this->probe( <<<'PHP'
$calls=0;$resolver=static function()use(&$calls){++$calls;return 'secret';};$registrar=require $data['bootstrap'];$handle=$registrar->plugin('github',$data['plugin'],'acme/example','123456789','stable','manual',$resolver);$handle->register();$broker=$GLOBALS['ran_wp_release_updater_v1_broker'];$broker->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);
if ('stale_global' === $data['fault']) {$GLOBALS['ran_wp_release_updater_v1_broker']=new stdClass();}
if ('wrong_protocol' === $data['fault']) {$GLOBALS['ran_wp_release_updater_v1_broker']=new class { public function protocolVersion(): int { return 1; } };}
if ('legacy_broker' === $data['fault']) {$GLOBALS['ran_wp_github_release_updater_v1_broker']=new stdClass();}
if ('legacy_marker' === $data['fault']) {function ran_wp_github_release_updater_v1_has_registered_target(): bool { return true; }}
$answers=[];$native=null;foreach($GLOBALS['p0_1_hooks'] as $registered){$hook=$registered['hook'];$callback=$registered['callback'];$native=$callback[0];if('update_plugins_github.com'===$hook)$answers[$hook]=$callback(false,['Version'=>'1.0.0','UpdateURI'=>'https://github.com/acme/example'],'plugin/plugin.php',[]);if('plugins_api'===$hook)$answers[$hook]=$callback('info','plugin_information',(object)['slug'=>'ran-wp-release-updater-'.substr(hash('sha256',"plugin\0plugin/plugin.php"),0,24)]);if('auto_update_plugin'===$hook)$answers[$hook]=$callback(true,(object)['plugin'=>'plugin/plugin.php','package'=>'sentinel']);if('upgrader_package_options'===$hook)$answers[$hook]=$callback(['hook_extra'=>['plugin'=>'plugin/plugin.php','action'=>'update','type'=>'plugin']]);if('upgrader_pre_download'===$hook)$answers[$hook]=$callback('reply','sentinel',null,['plugin'=>'plugin/plugin.php','action'=>'update','type'=>'plugin']);if('upgrader_pre_install'===$hook)$answers[$hook]=$callback('install',['plugin'=>'plugin/plugin.php','action'=>'update','type'=>'plugin']);if('pre_unzip_file'===$hook)$answers[$hook]=$callback('unzip','sentinel','destination',[],0.0);if('upgrader_source_selection'===$hook)$answers[$hook]=$callback('source','remote',null,['plugin'=>'plugin/plugin.php','action'=>'update','type'=>'plugin']);if('upgrader_install_package_result'===$hook)$answers[$hook]=$callback('result',['plugin'=>'plugin/plugin.php','action'=>'update','type'=>'plugin']);if('upgrader_process_complete'===$hook)$callback(null,['action'=>'update','type'=>'plugin','plugins'=>['plugin/plugin.php']]);}$native->finalizePendingInstall();echo json_encode(['answers'=>$answers,'calls'=>$calls,'status'=>$handle->status(),'diagnostics'=>$handle->diagnostics(),'hooks'=>count($GLOBALS['p0_1_hooks'])]);
PHP, array( 'fault' => $fault ) );

			self::assertSame(
				array(
					'update_plugins_github.com' => false,
					'plugins_api' => 'info',
					'auto_update_plugin' => true,
					'upgrader_package_options' => array( 'hook_extra' => array( 'plugin' => 'plugin/plugin.php', 'action' => 'update', 'type' => 'plugin' ) ),
					'upgrader_pre_download' => 'reply',
					'upgrader_pre_install' => 'install',
					'pre_unzip_file' => 'unzip',
					'upgrader_source_selection' => 'source',
					'upgrader_install_package_result' => 'result',
				),
				$result['answers'],
				$fault
			);
			self::assertSame( 0, $result['calls'], $fault );
			self::assertSame( 'protocol_conflict_inactive', $result['status']['code'], $fault );
			self::assertTrue( $result['status']['hooks_registered'], $fault );
			self::assertIsArray( $result['status']['native'], $fault );
			self::assertSame( array( 'state' => 'inactive', 'diagnostics' => array( array( 'code' => 'protocol_conflict_inactive' ) ) ), $result['diagnostics'], $fault );
			self::assertSame( 10, $result['hooks'], $fault );
		}
	}

	public function testNativeRefreshFalseIsNonterminalForAnOtherwiseActiveHandle(): void
	{
		$result = $this->probe( <<<'PHP'
$registrar=require $data['bootstrap'];$handle=$registrar->plugin('github',$data['plugin'],'acme/example','123456789');$handle->register();$broker=$GLOBALS['ran_wp_release_updater_v1_broker'];$broker->activate(['php_version'=>PHP_VERSION,'runtime_protocol'=>2,'wordpress_version'=>'6.8.0']);$submissions=(new ReflectionProperty($broker,'submissions'))->getValue($broker);$target=$submissions[1]['handle'];(new ReflectionProperty($target,'native'))->setValue($target,new class { public function refresh(): bool{return false;} public function status(): array{return ['candidate_header_version'=>null,'candidate_tag'=>null,'candidate_validation_code'=>null,'candidate_version'=>null,'failure_code'=>null,'installed_version'=>null,'last_check'=>null,'offered_version'=>null,'relationship'=>null];} public function diagnostics(): array{return ['state'=>'active','diagnostics'=>[]];} });echo json_encode(['refresh'=>$handle->refresh(),'status'=>$handle->status(),'broker'=>$broker->diagnostics()]);
PHP );

		self::assertFalse( $result['refresh'] );
		self::assertSame( 'target_active', $result['status']['code'] );
		self::assertSame( 'active', $result['broker']['state'] );
		self::assertSame( array(), $result['broker']['diagnostics'] );
	}

	public function testLexicalRejectionsAreStableAndRetainedByTheHandle(): void
	{
		$result = $this->probe( <<<'PHP'
$r=require $data['bootstrap'];$cases=[['file'=>'relative','provider'=>'github','locator'=>'acme/example','identity'=>'123','channel'=>'stable','policy'=>'manual','code'=>'installed_file_invalid'],['file'=>$data['plugin'],'provider'=>'GitHub','locator'=>'acme/example','identity'=>'123','channel'=>'stable','policy'=>'manual','code'=>'provider_code_invalid'],['file'=>$data['plugin'],'provider'=>'github','locator'=>'bad locator','identity'=>'123','channel'=>'stable','policy'=>'manual','code'=>'repository_locator_invalid'],['file'=>$data['plugin'],'provider'=>'github','locator'=>'acme/example','identity'=>'bad id','channel'=>'stable','policy'=>'manual','code'=>'repository_identity_invalid'],['file'=>$data['plugin'],'provider'=>'github','locator'=>'acme/example','identity'=>'123','channel'=>'nightly','policy'=>'manual','code'=>'release_channel_invalid'],['file'=>$data['plugin'],'provider'=>'github','locator'=>'acme/example','identity'=>'123','channel'=>'stable','policy'=>'nope','code'=>'update_policy_invalid']];$out=[];foreach($cases as $case){$h=$r->plugin($case['provider'],$case['file'],$case['locator'],$case['identity'],$case['channel'],$case['policy']);$a=$h->register();$out[]=[$a,$h->register(),$h->status(),$h->diagnostics()];}echo json_encode($out);
PHP );
		$codes = array( 'installed_file_invalid', 'provider_code_invalid', 'repository_locator_invalid', 'repository_identity_invalid', 'release_channel_invalid', 'update_policy_invalid' );
		foreach ( $result as $index => $row ) { self::assertFalse( $row[0] ); self::assertFalse( $row[1] ); self::assertSame( $codes[ $index ], $row[2]['code'] ); self::assertFalse( $row[2]['declaration_accepted'] ); self::assertFalse( $row[2]['hooks_registered'] ); self::assertNull( $row[2]['native'] ); self::assertSame( array( array( 'code' => $codes[ $index ] ) ), $row[3]['diagnostics'] ); }
	}

	/** @return array<string,mixed> */
	/** @param array<string,mixed> $extra */
	private function probe( string $body, array $extra = array() ): array
	{
		$file = $this->root . '/probe.php';
		$data = array_merge( array( 'bootstrap' => dirname( __DIR__, 2 ) . '/bootstrap.php', 'plugin' => $this->root . '/plugin/plugin.php', 'theme' => $this->root . '/theme/style.css', 'manager' => $this->root . '/manager-theme/style.css', 'forced' => $this->root . '/forced-plugin/main.php', 'late' => $this->root . '/late-plugin/main.php', 'canonical' => $this->root . '/canonical/plugin.php', 'mismatch' => $this->root . '/mismatch/plugin.php', 'root' => $this->root ), $extra );
		$prefix = '<?php define("WP_PLUGIN_DIR", ' . var_export( $this->root, true ) . '); function add_filter(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["p0_1_hooks"][]=array("hook"=>$hook,"callback"=>$callback);} function add_action(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["p0_1_hooks"][]=array("hook"=>$hook,"callback"=>$callback);} $GLOBALS["p0_1_hooks"]=array();$GLOBALS["wpdb"]=new stdClass();$GLOBALS["wp_version"]="6.8.0";$GLOBALS["wp_theme_directories"]=array(' . var_export( $this->root, true ) . ');$data=' . var_export( $data, true ) . ';';
		file_put_contents( $file, $prefix . $body );
		exec( escapeshellarg( PHP_BINARY ) . ' -n -d sys_temp_dir=' . escapeshellarg( $this->root ) . ' ' . escapeshellarg( $file ), $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		return json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
	}
}
