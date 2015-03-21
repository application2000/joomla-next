<?php
/**
 * Joomla! Next Installation Application
 *
 * @copyright  Copyright (C) Open Source Matters, Inc. All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License Version 2 or Later
 */

namespace Installation;

use Joomla\Application\AbstractWebApplication;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\DI\ContainerAwareInterface;
use Joomla\DI\ContainerAwareTrait;
use Joomla\Language\Language;
use Joomla\Registry\Registry;
use Joomla\Session\Session;

/**
 * CMS installation application class.
 *
 * @since  1.0
 */
final class Application extends AbstractWebApplication implements ContainerAwareInterface
{
	use ContainerAwareTrait;

	/**
	 * Language instance
	 *
	 * @var    Language
	 * @since  1.0
	 */
	private $language;

	/**
	 * Method to run the application routines.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	protected function doExecute()
	{
		// Register this to the DI container now
		$this->getContainer()->protect('Installation\\Application', $this)
			->alias('Joomla\\CMS\\Application\\CMSApplicationInterface', 'Installation\\Application')
			->alias('Joomla\\Application\\AbstractWebApplication', 'Installation\\Application')
			->alias('Joomla\\Application\\AbstractApplication', 'Installation\\Application')
			->alias('app', 'Installation\\Application');

		$this->initialiseApp();

		try
		{
			$controller = $this->fetchController($this->input->getCmd('task', 'display'));
			$contents   = $controller->execute();

			// If debug language is set, append its output to the contents.
			if ($this->get('language.debug'))
			{
				$contents .= $this->debugLanguage();
			}
		}
		catch (\Exception $e)
		{
			echo $e->getMessage();
			$this->close($e->getCode());
		}
	}

	/**
	 * Fetch a controller for the requested task
	 *
	 * @param   string  $task  The task being executed in a dotted notation (i.e. install.config)
	 *
	 * @return  BaseController
	 *
	 * @since   1.0
	 * @throws  \RuntimeException if a controller for the task is not found
	 */
	protected function fetchController($task)
	{
		// Explode the task out so we can assemble the name
		$pieces = explode('.', $task);

		// Set the controller class name based on the task.
		$class = __NAMESPACE__ . '\\Controller';

		foreach ($pieces as $piece)
		{
			$class .= '\\' . ucfirst(strtolower($piece));
		}

		// If the requested controller exists let's use it.
		if (class_exists($class))
		{
			return $this->getContainer()->buildObject($class);
		}

		// Nothing found. Panic.
		throw new \RuntimeException(
			$this->language->getText()->sprintf('INSTL_CONTROLLER_NOT_FOUND', $task)
		);
	}

	/**
	 * Returns the language code and help url set in the localise.xml file.
	 *
	 * Used for forcing a particular language in localised releases.
	 *
	 * @return  array|boolean  False on failure, array on success.
	 *
	 * @since   1.0
	 */
	public function getLocalise()
	{
		$localiseFile = JPATH_INSTALLATION . '/localise.xml';

		// Does the file even exist?
		if (!file_exists($localiseFile))
		{
			return false;
		}

		$xml = simplexml_load_file($localiseFile);

		if (!$xml)
		{
			return false;
		}

		// Check that it's a localise file.
		if ($xml->getName() != 'localise')
		{
			return false;
		}

		return [
			'language'   => (string) $xml->forceLang,
			'helpurl'    => (string) $xml->helpurl,
			'debug'      => (string) $xml->debug,
			'sampledata' => (string) $xml->sampledata
		];
	}

	/**
	 * Gets the name of the current running application.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function getName()
	{
		return 'installation';
	}

	/**
	 * Custom initialisation method.
	 *
	 * Called at the end of the AbstractApplication::__construct method.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	protected function initialise()
	{
		// Enable sessions by default.
		$this->config->def('session', true);

		// Set the session default name.
		$this->config->def('session_name', 'installation');

		// Create the session if a session name is passed.
		if ($this->get('session') !== false)
		{
			$this->loadSession();
		}

		// Store the debug value to config based on the JDEBUG flag.
		$this->set('debug', JDEBUG);
	}

	/**
	 * Initialise the application.
	 *
	 * @param   array  $options  An optional associative array of configuration settings.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	public function initialiseApp($options = array())
	{
		// Create the base URI object for the application
		$uri = Uri::getInstance();

		// Set the base URI
		$baseUri = (array) $this->get('uri.base');
		$uri->setBase($baseUri);

		// Now set the root URI
		$parts = explode('/', $baseUri['full']);
		array_pop($parts);
		$baseUri['full'] = implode('/', $parts);
		$uri->setRoot($baseUri);

		// Check for localisation information provided in a localise.xml file.
		$forced = $this->getLocalise();

		// Check the request data for the language.
		if (empty($options['language']))
		{
			$requestLang = $this->input->getCmd('lang', null);

			if (!is_null($requestLang))
			{
				$options['language'] = $requestLang;
			}
		}

		// Check the session for the language.
		if (empty($options['language']))
		{
			$sessionOptions = $this->getSession()->get('setup.options');

			if (isset($sessionOptions['language']))
			{
				$options['language'] = $sessionOptions['language'];
			}
		}

		// This could be a first-time visit - try to determine what the client accepts.
		if (empty($options['language']))
		{
			if (!empty($forced['language']))
			{
				$options['language'] = $forced['language'];
			}
			else
			{
				$languageHelper = new LanguageHelper;
				$languageHelper->setContainer($this->getContainer());
				$options['language'] = $languageHelper->detectLanguage();

				if (empty($options['language']))
				{
					$options['language'] = 'en-GB';
				}
			}
		}

		// Last resort, give the user English.
		if (empty($options['language']))
		{
			$options['language'] = 'en-GB';
		}

		// Check for a custom help URL.
		if (empty($forced['helpurl']))
		{
			$options['helpurl'] = 'https://help.joomla.org/proxy/index.php?option=com_help&amp;keyref=Help{major}{minor}:{keyref}';
		}
		else
		{
			$options['helpurl'] = $forced['helpurl'];
		}

		// Store the help URL in the session.
		$this->getSession()->set('setup.helpurl', $options['helpurl']);

		// Set the language configuration.
		$this->set('language.code', $options['language']);
		$this->set('language.debug', $forced['debug']);
		$this->set('sampledata', $forced['sampledata']);
		$this->set('helpurl', $options['helpurl']);

		// Instantiate our Langauge instance
		$this->language = Language::getInstance($this->get('language.code'), JPATH_INSTALLATION, $this->get('language.debug'));
		$this->getContainer()->share('Joomla\\Language\\Language', $this->language);
	}

	/**
	 * Load the application session.
	 *
	 * @param   Session  $session  An optional Session object.
	 *
	 * @return  $this
	 *
	 * @since   1.0
	 */
	public function loadSession(Session $session = null)
	{
		// Generate a session name.
		$name = md5($this->get('secret') . $this->get('session_name', get_class($this)));

		// Calculate the session lifetime.
		$lifetime = (($this->get('lifetime')) ? $this->get('lifetime') * 60 : 900);

		// Get the session handler from the configuration.
		$handler = $this->get('session_handler', 'none');

		// Initialize the options for Session.
		$options = array(
			'name'      => $name,
			'expire'    => $lifetime,
			'force_ssl' => $this->get('force_ssl')
		);

		// Instantiate the session object.
		$session = Session::getInstance($handler, $options);
		$session->initialise($this->input);

		if ($session->getState() == 'expired')
		{
			$session->restart();
		}
		else
		{
			$session->start();
		}

		if (!$session->get('registry') instanceof Registry)
		{
			// Registry has been corrupted somehow.
			$session->set('registry', new Registry('session'));
		}

		// Set the session object.
		$this->setSession($session);

		return $this;
	}
}
