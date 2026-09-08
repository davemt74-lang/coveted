<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{
    $value=@file_get_contents($root.'/'.ltrim($path,'/'));
    if($value===false){fwrite(STDERR,"Missing required file: {$path}\n");exit(1);}return $value;
};
$contains=static function(string $content,string $needle,string $label):void{
    if(!str_contains($content,$needle)){fwrite(STDERR,"Deployment readiness contract failed: {$label}\n");exit(1);}
};
$missing=static function(string $content,string $needle,string $label):void{
    if(str_contains($content,$needle)){fwrite(STDERR,"Deployment readiness contract failed: {$label}\n");exit(1);}
};

$deployment=$read('app/deployment.php');
$preflight=$read('scripts/preflight.php');
$readme=$read('README.md');
$composer=$read('composer.json');
$latestMigration=$read('database/migrations/20260908_member_relationship_actions.sql');

$composerData=json_decode($composer,true,512,JSON_THROW_ON_ERROR);
$phpConstraint=(string)($composerData['require']['php']??'');
if($phpConstraint!=='>=8.2'){
    fwrite(STDERR,"Deployment readiness contract failed: Composer deploy runtime must remain PHP >=8.2.\n");exit(1);
}

$contains($deployment,"version_compare(PHP_VERSION, '8.2.0', '<')",'preflight runtime must match Composer PHP >=8.2 requirement');
$contains($deployment,'function coveted_deployment_migration_inventory_issues','migration inventory validation is required');
$contains($deployment,"'/^\\d{8}_[a-z0-9_]+\\.sql$/'",'migration filenames must be ordered/date-prefixed SQL');
$contains($deployment,"'20260908_member_relationship_actions.sql'",'current release migration must be a package prerequisite');
$contains($deployment,"'member_relationship_actions'",'current release table must be a schema prerequisite');
$contains($deployment,'function coveted_deployment_migration_tables','migration-created tables must be discovered');
$contains($deployment,'function coveted_deployment_expected_tables','baseline and migration-created tables must share one expected schema read model');
$contains($deployment,'array_merge($tables, coveted_deployment_migration_tables($migrationDir))','expected deployed schema must include migration-created tables');
$contains($deployment,'Apply the corresponding additive migration(s) before deploying code.','missing schema must fail with explicit upgrade guidance');
$missing($deployment,'database/migrations is not empty','migrations must not be rejected after first production install');
$missing($deployment,'First install must use database/schema.sql only','preflight must not use obsolete pre-production schema policy');

$contains($preflight,"['--expect-empty', '--fresh']",'fresh-install preflight alias is required');
$contains($preflight,"['--expect-installed', '--upgrade']",'upgrade preflight alias is required');
$contains($preflight,"$root . '/database/migrations'",'live preflight must include migration-created tables');
$contains($preflight,'Current release migration prerequisite','preflight must show the current release SQL prerequisite');
$contains($preflight,'Coveted deployment preflight','preflight must no longer identify itself as first-install-only');
$missing($preflight,'->exec($sql)','preflight must never execute migration SQL');
$missing($preflight,'file_get_contents($root . \'/database/migrations/','preflight controller must not load migration SQL for execution');

$contains($readme,'Coveted has crossed the first-production-install boundary.','documentation must reflect deployed production lifecycle');
$contains($readme,'php scripts/preflight.php --upgrade --production','upgrade deployment command must be documented');
$contains($readme,'The deployment preflight is read-only.','documentation must preserve explicit migration authority');
$contains($readme,'never applies or modifies schema','documentation must forbid automatic preflight DDL');

$contains($latestMigration,'CREATE TABLE IF NOT EXISTS member_relationship_actions','latest required migration must create the required durable action table');

$migrationDir=$root.'/database/migrations';
$files=array_values(array_filter(scandir($migrationDir)?:[],static fn(string $name):bool=>$name!=='.'&&$name!=='..'));
sort($files,SORT_STRING);
if($files===[]){fwrite(STDERR,"Deployment readiness contract failed: migration inventory is empty.\n");exit(1);}
foreach($files as $name){
    if(!preg_match('/^\d{8}_[a-z0-9_]+\.sql$/',$name)){
        fwrite(STDERR,"Deployment readiness contract failed: invalid migration filename {$name}.\n");exit(1);
    }
    $sql=@file_get_contents($migrationDir.'/'.$name);
    if($sql===false||trim($sql)===''){
        fwrite(STDERR,"Deployment readiness contract failed: empty migration {$name}.\n");exit(1);
    }
}

fwrite(STDOUT,"Production deployment readiness contract verified.\n");
