<?php

declare(strict_types=1);

namespace Deable\NextrasEventsRelay;

use Deable\NextrasEvents\EventsMapping;
use Nette\Application\Application;
use Nette\Application\IPresenter;
use Nette\ComponentModel\Container;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\ServiceCreationException;
use Nette\Utils\Strings;
use Nextras\Orm\Repository\IRepository;
use ReflectionClass;
use ReflectionException;


final class EventsRelayExtension extends CompilerExtension
{

	/**
	 * @throws ReflectionException
	 */
	public function beforeCompile(): void
	{
		$entities = $this->loadEntities();

		$builder = $this->getContainerBuilder();
		$builder->addDefinition($this->prefix('relay'))
			->setType(PresenterRelay::class)
			->setArgument('listeners', $this->loadListeners($entities));

		/** @var ServiceDefinition $application */
		$application = $builder->getDefinitionByType(Application::class);
		$application->addSetup('$service->onPresenter[] = [?, "setPresenter"]', [$this->prefix('@relay')]);
	}

	/**
	 * @return array<string, string>
	 */
	private function loadEntities(): array
	{
		$entities = [];

		$builder = $this->getContainerBuilder();
		$repositories = $builder->findByType(IRepository::class);

		foreach ($repositories as $repository) {
			assert($repository instanceof ServiceDefinition);

			$repositoryClass = $repository->getType();
			if (!class_exists($repositoryClass)) {
				throw new ServiceCreationException("Repository class '$repositoryClass' not found");
			}
			if (!method_exists($repositoryClass, 'getEntityClassNames')) {
				continue;
			}
			foreach ($repositoryClass::getEntityClassNames() as $entity) {
				$entities[] = $entity;
			}
		}

		return $entities;
	}

	/**
	 * @param string[] $entities
	 *
	 * @return array<string, array<string, array<string, string[]>>>
	 * @throws ReflectionException
	 */
	private function loadListeners(array $entities): array
	{
		$builder = $this->getContainerBuilder();
		/** @var ServiceDefinition[] $presenters */
		$presenters = $builder->findByType(IPresenter::class);

		$events = [];
		foreach ($presenters as $presenter) {
			$presenterClass = $presenter->getType();
			foreach ($this->analysePresenter($entities, $presenterClass) as $id => $components) {
				$events[$id][$presenterClass] = $components;
			}
		}

		$listeners = [];
		foreach ($events as $event => $presenters) {
			foreach ($presenters as $presenter => $components) {
				foreach ($components as $component => $componentEntities) {
					foreach ($componentEntities as $entity) {
						$listeners[$presenter][$entity][$event][] = $component;
					}
				}
			}
		}

		return $listeners;
	}

	/**
	 * @param string[] $entities
	 * @param string $presenterClass
	 *
	 * @return array<string, array<string, string[]>>
	 * @throws ReflectionException
	 */
	private function analysePresenter(array $entities, string $presenterClass): array
	{
		$stack = [[null, $presenterClass]];

		$listeners = [];
		while ([$name, $componentClass] = array_shift($stack)) {
			foreach ($this->analyseComponent($entities, $componentClass) as $id => $events) {
				$listeners[$id][$name] = $events;
			}

			$reflection = new ReflectionClass($componentClass);
			if ($reflection->isSubclassOf(Container::class)) {
				foreach ($reflection->getMethods() as $method) {
					$match = Strings::match($method->getName(), '~^createComponent(.+)$~');
					if ($match === null) {
						continue;
					}

					$returnType = $method->getReturnType();
					if ($returnType === null) {
						continue;
					}

					$stack[] = [
						($name ? $name . Container::NAME_SEPARATOR : '') . lcfirst($match[1]),
						$returnType->getName()
					];
				}
			}
		}

		return $listeners;
	}

	/**
	 * @param string[] $entities
	 * @param string $componentClass
	 *
	 * @return array<string, string[]>
	 * @throws ReflectionException
	 */
	private function analyseComponent(array $entities, string $componentClass): array
	{
		$reflection = new ReflectionClass($componentClass);

		$listeners = [];
		foreach (EventsMapping::get() as $attributeClass => $events) {
			$attributes = $reflection->getAttributes($attributeClass);
			foreach ($attributes as $attribute) {
				[$entityClass] = $attribute->getArguments();
				if (!in_array($entityClass, $entities, true)) {
					$attr = $attribute->getName();
					throw new ServiceCreationException("Attribute '$attr' on '$componentClass' should use entity class");
				}

				foreach ($events as $event => $interface) {
					if (!$reflection->implementsInterface($interface)) {
						throw new ServiceCreationException("Object '$componentClass' should implement '$interface'");
					}
					$listeners[$event][] = $entityClass;
				}
			}
		}

		return $listeners;
	}

}
