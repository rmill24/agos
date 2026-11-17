<?php

namespace App\Data;

readonly class TeamPermissions
{
    public function __construct(
        public bool $canViewBusiness,
        public bool $canViewReports,
        public bool $canManageFinancialRecords,
        public bool $canRunScenarios,
        public bool $canViewRecommendations,
        public bool $canManageBusinessUsers,
        public bool $canManageBusinessSettings,
        public bool $canViewBusinessAuditEvents,
        public bool $canUpdateTeam,
        public bool $canDeleteTeam,
        public bool $canAddMember,
        public bool $canUpdateMember,
        public bool $canRemoveMember,
        public bool $canCreateInvitation,
        public bool $canCancelInvitation,
    ) {
        //
    }
}
