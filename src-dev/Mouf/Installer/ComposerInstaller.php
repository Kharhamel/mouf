<?php 
namespace Mouf\Installer;

use Composer\Package\PackageInterface;

use Mouf\MoufException;

use Mouf\Composer\ComposerService;

/**
 * This class is in charge of finding what packages must be installed on the system.
 * 
 * @author David Negrier
 */
class ComposerInstaller {
	
	protected $selfEdit;
	protected $composerService;
	protected $globalInstallFile;
	protected $localInstallFile;
	/**
	 * The file containing the actions to be performed after an install task has been performed.
	 * It is used to replace a session mechanism that cannot be installed.
	 *  
	 * @var string
	 */
	protected $installStatusFile;
	
	/**
	 * 
	 * @var AbstractInstallTask[]
	 */
	protected $installTasks = null;
	
	public function __construct($selfEdit = false) {
		$this->selfEdit = $selfEdit;
		$this->composerService = new ComposerService($selfEdit);

		if ($this->selfEdit == false) {
			$this->globalInstallFile = __DIR__."/../../../../../../mouf/installs_app.php";
			$this->localInstallFile = __DIR__."/../../../../../../mouf/no_commit/local_installs_app.php";
			$this->installStatusFile = __DIR__."/../../../../../../mouf/no_commit/install_status.json";
		} else {
			$this->globalInstallFile = __DIR__."/../../../../../../mouf/installs_moufui.php";
			$this->localInstallFile = __DIR__."/../../../../../../mouf/no_commit/local_installs_moufui.php";
			$this->installStatusFile = __DIR__."/../../../../../../mouf/no_commit/install_status_moufui.json";
		}
	}
	
	
	/**
	 * Returns an ordered list of packages that have an install procedure, with a status saying if
	 * the installation has been performed or not.
	 * 
	 * @return AbstractInstallTask[]
	 */
	public function getInstallTasks() {
		if ($this->installTasks === null) {
			$this->load();
		}
		return $this->installTasks;
	}
	
	/**
	 * Saves any modified install task.
	 */
	public function save() {
		if ($this->installTasks === null) {
			return;
		}
		
		// Let's grab all install tasks and save them according to their scope.
		// If no scope is provided, we default to global scope.
		
		$localInstalls = array();
		$globalInstalls = array();
		
		foreach ($this->installTasks as $task) {
			switch ($task->getScope()) {
				case AbstractInstallTask::SCOPE_GLOBAL:
				case '':
					$globalInstalls[] = $task->toArray();
					break;
				case AbstractInstallTask::SCOPE_LOCAL:
					$localInstalls[] = $task->toArray();
					break;
				default:
					throw new MoufException("Unknown install task scope '".$task->getScope()."'.");
			}
		}
		
		$this->ensureWritable($this->globalInstallFile);
		$this->ensureWritable($this->localInstallFile);
		
		$fp = fopen($this->globalInstallFile, "w");
		fwrite($fp, "<?php\n");
		fwrite($fp, "/**\n");
		fwrite($fp, " * This is a file automatically generated by the Mouf framework. Do not modify it, as it could be overwritten.\n");
		fwrite($fp, " * This file contains the status of all Mouf global installation processes for packages you have installed.\n");
		fwrite($fp, " * If you are working with a source repository, this file should be commited.\n");
		fwrite($fp, " */\n");
		fwrite($fp, "return ".var_export($globalInstalls, true).";");
		fclose($fp);		
		
		$fp = fopen($this->localInstallFile, "w");
		fwrite($fp, "<?php\n");
		fwrite($fp, "/**\n");
		fwrite($fp, " * This is a file automatically generated by the Mouf framework. Do not modify it, as it could be overwritten.\n");
		fwrite($fp, " * This file contains the status of all Mouf local installation processes for packages you have installed.\n");
		fwrite($fp, " * If you are working with a source repository, this file should NOT be commited.\n");
		fwrite($fp, " */\n");
		fwrite($fp, "return ".var_export($localInstalls, true).";");
		fclose($fp);		
	}
	
	/**
	 * Ensures that file $fileName is writtable.
	 * If it does not exist, this function will create the directory that contains the file if needed.
	 * If there is a problem with rights, it will throw an exception.
	 * 
	 * @param string $fileName
	 */
	private static function ensureWritable($fileName) {
		$directory = dirname($fileName);
		
		if (file_exists($fileName)) {
			if (is_writable($fileName)) {
				return;
			} else {
				throw new MoufException("File ".$fileName." is not writable.");
			}
		}
			
		if (!file_exists($directory)) {
			// The directory does not exist.
			// Let's try to create it.
			
			// Let's build the directory
			$oldumask = umask(0);
			$success = mkdir($directory, 0777, true);
			umask($oldumask);
			if (!$success) {
				throw new MoufException("Unable to create directory ".$directory);
			}
		}
		
		if (!is_writable($directory)) {
			throw new MoufException("Error, unable to create file ".$fileName.". The directory is not writtable.");
		}
	}
	
	private function load() {
		$packages = $this->composerService->getLocalPackagesOrderedByDependencies();
		// TODO: check the packages are in the right order?
		// Method: go through the tree, loading child first.
		// Each time we go through a package, lets ensure the package is not already part of the packages to install.
		// If so, ignore.
		
		
		
		$this->installTasks = array();
		
		foreach ($packages as $package) {
			$extra = $package->getExtra();
			if (isset($extra['mouf']['install'])) {
				$installSteps = $extra['mouf']['install'];
				if (!is_array($installSteps)) {
					throw new MoufException("Error in package '".$package->getPrettyName()."' in Mouf. The install parameter in composer.json (extra->mouf->install) should be an array of files/url to install.");
				}
					
				if ($installSteps) {
					if (self::isAssoc($installSteps)) {
						// If this is directly an associative array (instead of a numerical array of associative arrays)
						$this->installTasks[] = $this->getInstallStep($installSteps, $package);
					} else {
						foreach ($installSteps as $installStep) {
							$this->installTasks[] = $this->getInstallStep($installStep, $package);
						}
					}
				}
			}
		}
		
		// Let's find the install status (todo or complete)
		if (file_exists($this->globalInstallFile)) {
			$installStatuses = include $this->globalInstallFile;
			$this->completeStatusFromInstallFile($installStatuses);
		}
		
		if (file_exists($this->localInstallFile)) {
			$installStatuses = include $this->localInstallFile;
			$this->completeStatusFromInstallFile($installStatuses);
		}
	}
	
	/**
	 * Takes in input the array contained in an install file and completes the status of the
	 * install tasks from here.
	 * @param array $installStatuses
	 */
	private function completeStatusFromInstallFile(array $installStatuses) {
		foreach ($this->installTasks as $installTask) {
			/* @var $installTask AbstractInstallTask */
			foreach ($installStatuses as $installStatus) {
				if ($installTask->matchesPackage($installStatus)) {
					$installTask->setStatus($installStatus['status']);
				}
			}
		}
	}

	private function getInstallStep(array $installStep, PackageInterface $package) {
		
		if (!isset($installStep['type'])) {
			throw new MoufException("Warning! In composer.json, no type found for install file/url in package '".$package->getPrettyName()."'.");
		}
		
		if ($installStep['type'] == 'file') {
			$installer = new FileInstallTask();
			if (!isset($installStep['file'])) {
				throw new MoufException("Warning! In composer.json for package '".$package->getPrettyName()."', for install of type 'file', no file found.");
			}
			$installer->setFile($installStep['file']);
		} elseif ($installStep['type'] == 'url') {
			$installer = new UrlInstallTask();
			if (!isset($installStep['url'])) {
				throw new MoufException("Warning! In composer.json for package '".$package->getPrettyName()."', for install of type 'url', no URL found.");
			}
			$installer->setUrl($installStep['url']);
		} elseif ($installStep['type'] == 'class') {
			$installer = new ClassInstallTask();
			if (!isset($installStep['class'])) {
				throw new MoufException("Warning! In composer.json for package '".$package->getPrettyName()."', for install of type 'class', no class found.");
			}
			$installer->setClassName($installStep['class']);
		} else {
			throw new MoufException("Unknown type during install process.");
		}
		$installer->setPackage($package);
		if (isset($installStep['description'])) {
			$installer->setDescription($installStep['description']);
		}
		return $installer;
	}
	
	/**
	 * Returns if an array is associative or not.
	 *
	 * @param array $arr
	 * @return boolean
	 */
	private static function isAssoc($arr)
	{
		return array_keys($arr) !== range(0, count($arr) - 1);
	}
	
	/**
	 * Starts the install process for only only task.
	 * 
	 * @param array $task
	 */
	public function install(array $task) {
		// The kind of action to be performed is stored in a file (mouf/installActions.json)
		// This file contains an array that can be either:
		// { "type": "all" } => install all tasks marked "todo"
		// { "type": "one", "task": { // task details } } => install only the task passed in parameter
		self::ensureWritable($this->installStatusFile);
		file_put_contents($this->installStatusFile, json_encode(
			array("type"=>"one",
				"task"=>$task)));
		
		header("Location: ".MOUF_URL."installer/printInstallationScreen?selfedit=".json_encode($this->selfEdit));
	}
	
	/**
	 * Starts the install process for all tasks remaining in the "TODO" state.
	 */
	public function installAll() {
		self::ensureWritable($this->installStatusFile);
		file_put_contents($this->installStatusFile, json_encode(
			array("type"=>"all")));
		
		header("Location: ".MOUF_URL."installer/printInstallationScreen?selfedit=".json_encode($this->selfEdit));
	}
	
	/**
	 * Finds the InstallTask from the array representing it.
	 * @param array $array
	 * @return AbstractInstallTask
	 */
	private function findInstallTaskFromArray(array $array) {
		$installTasks = $this->getInstallTasks();
		foreach ($installTasks as $installTask) {
			if ($installTask->matchesPackage($array)) {
				return $installTask;
			}
		}
		
		throw new MoufException("Unable to find install tasking matching array passed in parameter.");
	}
	
	/**
	 * In the middle of an install process (when a installStatusFile exists), this will return
	 * the next install task to be performed.
	 * Will return null if no remaining install processes are to be run, or no installStatusFile is found.
	 * 
	 * @return AbstractInstallTask
	 */
	public function getNextInstallTask() {
		if (!file_exists($this->installStatusFile)) {
			return null;
		}
		
		$statusArray = json_decode(file_get_contents($this->installStatusFile), true);
		if ($statusArray['type'] == 'one') {
			$taskArray = $statusArray['task'];
			// Now, let's find the matching task and return it.
			return $this->findInstallTaskFromArray($taskArray);
		} else {
			$installTasks = $this->getInstallTasks();
			foreach ($installTasks as $installTask) {
				if ($installTask->getStatus() == AbstractInstallTask::STATUS_TODO) {
					return $installTask;
				} 
			}
			return null;
		}
		
	}
	
	/**
	 * Marks the current install task as "done".
	 */
	public function validateCurrentInstall() {
		if (!file_exists($this->installStatusFile)) {
			throw new MoufException("No install status file found. I don't know what install task should be run.");
		}
		
		$statusArray = json_decode(file_get_contents($this->installStatusFile), true);
		if ($statusArray['type'] == 'one') {
			$taskArray = $statusArray['task'];
			// Now, let's find the matching task and mark it "done".
			$installTask = $this->findInstallTaskFromArray($taskArray);
			$installTask->setStatus(AbstractInstallTask::STATUS_DONE);
			$this->save();
			
			// Finally, let's delete the install status file.
			unlink($this->installStatusFile);
		} else {
			$installTasks = $this->getInstallTasks();
			foreach ($installTasks as $installTask) {
				if ($installTask->getStatus() == AbstractInstallTask::STATUS_TODO) {
					$installTask->setStatus(AbstractInstallTask::STATUS_DONE);
					$this->save();
					break;
				}
			}
			$todoRemaining = false;
			foreach ($installTasks as $installTask) {
				if ($installTask->getStatus() == AbstractInstallTask::STATUS_TODO) {
					$todoRemaining = true;
					break;
				}
			}
			if (!$todoRemaining) {
				// Finally, let's delete the install status file if there are no actions in "todo" state
				unlink($this->installStatusFile);
			}
		}
		
	}
}
