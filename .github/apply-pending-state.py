from pathlib import Path
import hashlib
import json

p = Path('src/WordPress/NativePluginUpdater.php')
text = p.read_text()
property_start = text.index("\tprivate bool $pending                   = false;")
property_end = text.index("\tprivate bool $queuedMultiRun", property_start)
text = text[:property_start] + text[property_end:]
text = text.replace("\tprivate ?AcquisitionReceipt $pendingReceipt = null;\n", "", 1)
anchor = "\tprivate StagedPackageManifest $manifestBuilder;\n"
text = text.replace(anchor, anchor + "\tprivate PendingInstallState $pendingInstall;\n", 1)
text = text.replace(
    "\t\t$this->archiveStore    = new OwnedArchiveStore();\n\t\t$this->manifestBuilder = new StagedPackageManifest();",
    "\t\t$this->archiveStore    = new OwnedArchiveStore();\n\t\t$this->manifestBuilder = new StagedPackageManifest();\n\t\t$this->pendingInstall  = new PendingInstallState();",
    1,
)
old = "\t\t$this->state                  = $verified['current'];\n\t\t$this->pendingReceipt         = $receipt;\n\t\t$this->pending                = true;\n\t\t$this->multiRun               = $queuedMultiRun;\n\t\t$this->pendingArchive         = $owned['path'];\n\t\t$this->ownedArchiveDirectory  = $owned['directory'];\n\t\t$this->pendingArchiveIdentity = $identity;"
new = "\t\t$this->state = $verified['current'];\n\t\t$this->pendingInstall->begin( $owned['path'], $owned['directory'], $identity, $receipt, $queuedMultiRun );"
if old not in text:
    raise SystemExit('missing pending begin block')
text = text.replace(old, new, 1)
text = text.replace("\t\t$this->state              = $verified['current'];\n\t\t$this->extractionAdmitted = true;", "\t\t$this->state = $verified['current'];\n\t\tif ( ! $this->pendingInstall->admitExtraction() ) {\n\t\t\t$this->clearPending();\n\t\t\treturn $this->failure( 'archive_changed_before_extraction' );\n\t\t}", 1)
text = text.replace("\t\t$this->state          = $verified['current'];\n\t\t$this->stagedManifest = $manifest;", "\t\t$this->state = $verified['current'];\n\t\tif ( ! $this->pendingInstall->stageManifest( $manifest ) ) {\n\t\t\t$this->clearPending();\n\t\t\treturn $this->failure( 'staged_package_identity_invalid' );\n\t\t}", 1)
text = text.replace("\t\t$this->installResultCaptured = true;\n\t\t$this->installResult         = $result;", "\t\tif ( ! $this->pendingInstall->captureInstallResult( $result ) ) {\n\t\t\t$this->clearPending();\n\t\t\treturn $this->failure( 'unverified_install_result' );\n\t\t}", 1)
text = text.replace("\t\t\t$this->completionObserved = true;", "\t\t\t$this->pendingInstall->observeCompletion();", 1)
start = text.index("\tprivate function clearPending( bool $release = true ): void {")
end = text.index("\tprivate function diagnose(", start)
clear_method = '''\tprivate function clearPending( bool $release = true ): void {
\t\t$this->clearDiscoverySnapshot();
\t\t$this->archiveStore->remove( $this->pendingInstall->archive(), $this->pendingInstall->archiveDirectory() );
\t\t$this->pendingInstall->clear();
\t\tif ( $release && $this->leaseHeld && $this->state instanceof BindingState ) {
\t\t\tReleaseOperationCoordinator::releasePersistentBindingState( $this->wpdb, $this->state, $this->claim );
\t\t}
\t\tif ( $release ) {
\t\t\t$this->state     = null;
\t\t\t$this->claim     = null;
\t\t\t$this->leaseHeld = false;
\t\t}
\t}
'''
text = text[:start] + clear_method + text[end:]
replacements = {
    '$this->pendingArchiveIdentity': '$this->pendingInstall->archiveIdentity()',
    '$this->pendingArchive': '$this->pendingInstall->archive()',
    '$this->ownedArchiveDirectory': '$this->pendingInstall->archiveDirectory()',
    '$this->pendingReceipt': '$this->pendingInstall->receipt()',
    '$this->extractionAdmitted': '$this->pendingInstall->extractionAdmitted()',
    '$this->installResultCaptured': '$this->pendingInstall->installResultCaptured()',
    '$this->installResult': '$this->pendingInstall->installResult()',
    '$this->completionObserved': '$this->pendingInstall->completionObserved()',
    '$this->multiRun': '$this->pendingInstall->multiRun()',
    '$this->stagedManifest': '$this->pendingInstall->stagedManifest()',
    '$this->pending': '$this->pendingInstall->active()',
}
for old, new in replacements.items():
    text = text.replace(old, new)
p.write_text(text)

p = Path('runtime.php')
text = p.read_text()
anchor = "\t'RAN\\\\WPReleaseUpdater\\\\V1\\\\WordPress\\\\StagedPackageManifest' => 'src/WordPress/StagedPackageManifest.php',\n"
if 'WordPress\\\\PendingInstallState' not in text:
    text = text.replace(anchor, anchor + "\t'RAN\\\\WPReleaseUpdater\\\\V1\\\\WordPress\\\\PendingInstallState' => 'src/WordPress/PendingInstallState.php',\n", 1)
p.write_text(text)

p = Path('phpstan.neon')
text = p.read_text()
anchor = "\t\t- src/WordPress/StagedPackageManifest.php\n"
if 'src/WordPress/PendingInstallState.php' not in text:
    text = text.replace(anchor, anchor + "\t\t- src/WordPress/PendingInstallState.php\n", 1)
p.write_text(text)

files = sorted(['bootstrap.php', 'runtime.php'] + [str(path) for path in Path('src').rglob('*.php')])
payload = b''.join(path.encode() + b'\0' + hashlib.sha256(Path(path).read_bytes()).hexdigest().encode() + b'\n' for path in files)
r = Path('runtime-copy.json')
data = json.loads(r.read_text())
data['package_revision'] = hashlib.sha256(payload).hexdigest()
r.write_text(json.dumps(data, indent=2) + '\n')
