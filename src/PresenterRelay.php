<?php

declare(strict_types=1);

namespace Deable\NextrasEventsRelay;

use Nette\Application\Application;
use Nette\Application\IPresenter;
use Nextras\Orm\Model\Model;


final class PresenterRelay
{
	private Model $model;

	private array $listeners;

	private ?IPresenter $presenter = null;

	private ?string $presenterClass = null;

	private array $cleanup = [];

	public function __construct(Model $model, array $listeners)
	{
		$this->model = $model;
		$this->listeners = $listeners;
	}

	public function setPresenter(Application $application, IPresenter $presenter): void
	{
		$this->unsubscribe();
		$this->subscribe($presenter);
	}

	private function subscribe(IPresenter $presenter): void
	{
		$class = get_class($presenter);
		if (isset($this->listeners[$class])) {
			$this->presenter = $presenter;
			$this->presenterClass = $class;

			foreach ($this->listeners[$class] as $entity => $events) {
				$repository = $this->model->getRepositoryForEntity($entity);

				foreach ($events as $event => $_) {
					$handler = fn (...$args) => $this->relay($entity, $event, $args);
					$repository->$event[] = $handler;
					$this->cleanup[$entity][$event] = $handler;
				}
			}
		}
	}

	private function unsubscribe(): void
	{
		foreach ($this->cleanup as $entity => $events) {
			$repository = $this->model->getRepositoryForEntity($entity);

			foreach ($events as $event => $handler) {
				$index = array_search($handler, $repository->$event, true);

				if (is_int($index)) {
					array_splice($repository->$event, $index, 1);
				}
			}
		}

		$this->presenter = $this->presenterClass = null;
		$this->cleanup = [];
	}

	private function relay(string $entity, string $event, array $arguments): void
	{
		if ($this->presenter === null) {
			return;
		}

		foreach ($this->listeners[$this->presenterClass][$entity][$event] ?? [] as $component) {
			call_user_func_array(
				[$component ? $this->presenter[$component] : $this->presenter, $event],
				$arguments
			);
		}
	}

}
