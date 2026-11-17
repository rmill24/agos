<?php

namespace App\Enums;

enum TeamPermission: string
{
    case ViewBusiness = 'business:view';
    case ViewReports = 'business:reports:view';
    case ManageFinancialRecords = 'financial-records:manage';
    case RunScenarios = 'scenarios:run';
    case ViewRecommendations = 'recommendations:view';
    case ManageBusinessUsers = 'business-users:manage';
    case ManageBusinessSettings = 'business-settings:manage';
    case ViewBusinessAuditEvents = 'business-audit:view';
    case ManageTechnicalPlatform = 'platform:manage';

    case UpdateTeam = 'team:update';
    case DeleteTeam = 'team:delete';

    case AddMember = 'member:add';
    case UpdateMember = 'member:update';
    case RemoveMember = 'member:remove';

    case CreateInvitation = 'invitation:create';
    case CancelInvitation = 'invitation:cancel';
}
