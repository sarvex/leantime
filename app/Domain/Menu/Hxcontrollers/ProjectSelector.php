<?php

namespace Leantime\Domain\Menu\Hxcontrollers;

use Illuminate\Contracts\Container\BindingResolutionException;
use Leantime\Core\Frontcontroller as FrontcontrollerCore;
use Leantime\Core\HtmxController;
use Leantime\Domain\Menu\Services\Menu;
use Leantime\Domain\Projects\Services\Projects;
use Leantime\Domain\Timesheets\Services\Timesheets;

/**
 *
 */
class ProjectSelector extends HtmxController
{
    /**
     * @var string
     */
    protected static string $view = 'menu::partials.projectSelector';

    /**
     * @var Timesheets
     */
    private Timesheets $timesheetService;
    private Menu $menuService;
    private \Leantime\Domain\Menu\Repositories\Menu $menuRepo;

    /**
     * Controller constructor
     *
     * @param Timesheets                              $timesheetService
     * @param Menu                                    $menuService
     * @param \Leantime\Domain\Menu\Repositories\Menu $menuRepo
     * @return void
     */
    public function init(Timesheets $timesheetService, Menu $menuService, \Leantime\Domain\Menu\Repositories\Menu $menuRepo): void
    {
        $this->timesheetService = $timesheetService;
        $this->menuService = $menuService;
        $this->menuRepo = $menuRepo;
    }

    /**
     * @return void
     * @throws BindingResolutionException
     */
    public function updateMenu(): void
    {

        $allAssignedprojects =
        $allAvailableProjects =
        $recentProjects =
        $returnVars = [];

        $projectSelectFilter = array(
            "groupBy" => $_POST['groupBy'] ?? "none",
            "clients" => $_POST['client'] ?? 0,
        );

        $_SESSION['userdata']["projectSelectFilter"] = $projectSelectFilter;

        if (isset($_SESSION['userdata'])) {
            $projectVars = $this->menuService->getUserProjectList($_SESSION['userdata']['id']);

            $allAssignedprojects = $projectVars['assignedProjects'];
            $allAvailableProjects  = $projectVars['availableProjects'];
            $allAvailableProjectsHierarchy  = $projectVars['availableProjectsHierarchy'];
            $allAssignedprojectsHierarchy  = $projectVars['assignedHierarchy'];
            $currentClient  = $projectVars['currentClient'];
            $menuType  = $projectVars['menuType'];
            $projectType  = $projectVars['projectType'];
            $recentProjects  = $projectVars['recentProjects'];
            $favoriteProjects = $projectVars['favoriteProjects'];
            $clients = $projectVars['clients'];
            $currentProject = $projectVars['currentProject'];
        }

        if (str_contains($redirectUrl = $this->incomingRequest->getRequestUri(), 'showProject')) {
            $redirectUrl = '/dashboard/show';
        }

        $projectTypeAvatars = $this->menuService->getProjectTypeAvatars();
        $projectSelectGroupOptions = $this->menuService->getProjectSelectorGroupingOptions();

        $this->tpl->assign('currentClient', $currentClient);
        $this->tpl->assign('module', FrontcontrollerCore::getModuleName());
        $this->tpl->assign('action', FrontcontrollerCore::getActionName());
        $this->tpl->assign('currentProjectType', $projectType);
        $this->tpl->assign('allAssignedProjects', $allAssignedprojects);
        $this->tpl->assign('allAvailableProjects', $allAvailableProjects);
        $this->tpl->assign('allAvailableProjectsHierarchy', $allAvailableProjectsHierarchy);
        $this->tpl->assign('projectHierarchy', $allAssignedprojectsHierarchy);
        $this->tpl->assign('recentProjects', $recentProjects);
        $this->tpl->assign('currentProject', $currentProject);
        $this->tpl->assign('menuStructure', $this->menuRepo->getMenuStructure($menuType) ?? []);
        $this->tpl->assign('settingsLink', [
            'label' => __('menu.project_settings'),
            'module' => 'projects',
            'action' => 'showProject',
            'settingsIcon' => __('menu.project_settings_icon'),
            'settingsTooltip' => __('menu.project_settings_tooltip'),
        ]);
        $this->tpl->assign('redirectUrl', $redirectUrl);
        $this->tpl->assign('projectTypeAvatars', $projectTypeAvatars);
        $this->tpl->assign('favoriteProjects', $favoriteProjects);
        $this->tpl->assign('projectSelectGroupOptions', $projectSelectGroupOptions);
        $this->tpl->assign('projectSelectFilter', $projectSelectFilter);
        $this->tpl->assign('clients', $clients);
    }

    /**
     * @return void
     */
    public function filter(): void
    {
    }
}
