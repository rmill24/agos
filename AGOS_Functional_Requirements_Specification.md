# AGOS Functional Requirements Specification (FRS)

**System:** AGOS --- Adaptive Guidance and Operations System\
**Document type:** Functional Requirements Specification\
**Version:** 1.2 — Laravel Teams and single-file Livewire baseline\
**Status:** Development baseline\
**Intended environment:** Local development, testing, and academic
demonstration\
**Currency and locale:** Philippine peso (PHP); `Asia/Manila` for
business-facing dates and times

------------------------------------------------------------------------

## Document purpose and basis

This specification defines AGOS's functional scope, user roles, business
rules, integration boundaries, safeguards, and testable acceptance criteria.

The selected implementation stack is:

- **Application:** Laravel 13 (PHP 8.3+) as a single modular monolith
- **Interface:** Livewire 4 single-file components (default), Laravel Blade, Tailwind CSS, and starter-kit Flux UI components
- **Teams and tenancy:** Official Laravel Livewire starter kit with Teams support enabled; one Laravel Team represents one AGOS Business
- **Charts:** Chart.js integrated into Livewire pages
- **Database:** PostgreSQL
- **Persistence:** Eloquent ORM and Laravel database migrations
- **Money arithmetic:** PostgreSQL `NUMERIC` and `brick/math` `BigDecimal` (or equivalently tested fixed-precision arithmetic)
- **Authentication:** Laravel's official Livewire starter kit, Fortify/session authentication, policies, and gates
- **Testing:** Pest or PHPUnit with Laravel HTTP/Livewire feature tests
- **Assets:** Vite for compiling CSS and small JavaScript utilities (no separate React application)
- **Version control:** Git and GitHub
- **Integrations:** Loyverse API, Groq or Gemini API, and Gotenberg hosted on Render

Use single-file Livewire components by default for ordinary pages, forms, CRUD workflows, and dashboard widgets. Use multi-file or class-based components only when component size, testing, or complexity makes separation beneficial. Keep financial calculations and integration logic in dedicated services/adapters, not in component bodies.

Laravel Teams is the canonical business-tenancy foundation. Extend the generated Team and team-membership schema for AGOS-specific business fields and roles; do not create duplicate Business or Membership tables.

AGOS remains a **modular monolith**. Livewire components use Laravel services directly for the web UI; JSON REST endpoints under `/api/v1` are implemented only where an external consumer or an explicit contract requires them. Provider-specific HTTP integrations remain isolated behind server-side adapters. The AGOS application and PostgreSQL run locally; only the Gotenberg PDF service is hosted on Render.

## 1. Introduction

### 1.1 Purpose

AGOS is a web-based cash-flow management and decision-support platform
for Philippine micro, small, and medium enterprises (MSMEs). It
consolidates cash-related business records and uses those records to
show current cash, forecast short-term cash flow, identify potential
cash shortages, simulate hypothetical situations, and present
evidence-supported actions.

This FRS defines what the system must do. It is intended for the
development team, project adviser, testers, and evaluators.

### 1.2 Product objectives

AGOS shall:

1.  Centralize income, expenses, invoices, payments, supplier bills, and
    related cash-flow records.
2.  Support CSV import for businesses with existing spreadsheet records.
3.  Retrieve sales transactions from Loyverse in read-only mode.
4.  Monitor receivables, payables, overdue collections, and upcoming
    expenses.
5.  Calculate current and projected cash using deterministic application
    logic.
6.  Identify when projected cash may fall below a business-defined
    cash-safety threshold.
7.  Let users test hypothetical financial events without changing actual
    financial records.
8.  Provide prioritized recommendations supported by AGOS records and
    calculations.
9.  Use AI to explain verified results, not to calculate official
    financial values or execute transactions.
10. Produce invoices and financial reports in PDF format.
11. Help businesses organize financial information for
    financing-readiness review without predicting loan approval.

### 1.3 Scope

**In scope**

-   User login, sessions, roles, and business-level data isolation
-   Business profile and cash-safety settings
-   Income and expense transactions
-   Transaction categorization and recurring financial records
-   Customer and supplier records
-   Invoice creation, payment recording, and receivables monitoring
-   Supplier bills, payable monitoring, and payment recording
-   CSV import with preview, mapping, validation, duplicate review, and
    error reporting
-   Read-only Loyverse sales synchronization
-   Cash position, forecast, cash-flow trends, and cash-gap calculations
-   Form-based scenario simulation
-   Purchase-affordability analysis
-   Rule-based and AI-assisted recommendations
-   Financing-readiness checklist and report
-   PDF invoices and reports through Gotenberg
-   Audit events and operational error handling
-   Local testing and demonstration

**Out of scope**

-   Production hosting, public release, or cloud deployment
-   Writing to, editing, or deleting records in Loyverse
-   Automatic payment execution or bank transfers
-   Automatic customer or supplier messaging
-   Loan approval, credit scoring, or prediction of financing approval
-   AI-controlled modification of financial records
-   Foreign-exchange conversion in the first release
-   Microservices architecture
-   Real-time Loyverse webhooks as a required first-release feature

### 1.4 Definitions

  -----------------------------------------------------------------------
  Term                                Meaning
  ----------------------------------- -----------------------------------
  Business                            One Laravel Team representing one
                                      MSME account. Its Team ID is the
                                      business scope for financial data.

  Team Owner                         The Laravel Team owner mapped to the
                                      AGOS Business Owner role.

  Team Member                        A user belonging to a business Team;
                                      the membership role maps to Manager
                                      or Bookkeeper / Finance Staff.

  Confirmed cash                      Cash already received or paid and
                                      supported by confirmed records.

  Expected cash                       A forecast inflow or outflow
                                      supported by a known record but not
                                      yet settled.

  Uncertain collection                An expected customer collection
                                      whose timing or receipt is
                                      uncertain.

  Optional future expense             A proposed or discretionary expense
                                      that has not been committed.

  Cash-safety threshold               The minimum cash balance defined by
                                      the business owner.

  Cash gap                            The amount by which projected cash
                                      falls below the cash-safety
                                      threshold.

  Scenario                            A hypothetical change used for
                                      simulation and kept separate from
                                      actual records.

  Adapter                             A module that isolates
                                      provider-specific API
                                      authentication, mapping,
                                      validation, errors, and retries.

  Evidence reference                  An identifier linking a
                                      recommendation or report statement
                                      to an AGOS record or calculation.
  -----------------------------------------------------------------------

## 2. Users and access roles

### 2.1 User roles

1.  **Business Owner** --- has full access to the business and may
    manage users, settings, financial records, scenarios, reports, and
    recommendations.
2.  **Manager** --- monitors cash flow, manages daily financial
    activities, and runs scenarios for the assigned business.
3.  **Bookkeeper / Finance Staff** --- maintains transactions, invoices,
    payments, bills, and reports for the assigned business.
4.  **System Administrator** --- manages technical platform settings and
    has no default access to business financial records.

### 2.2 Business tenancy and Laravel Teams mapping

The Laravel starter kit's Teams feature shall be enabled. Each Laravel
Team represents one AGOS Business, and the Team ID is the canonical
business identifier used to scope financial records.

- Team owner maps to **Business Owner**.
- Team membership roles map to **Manager** and **Bookkeeper / Finance Staff**.
- System Administrator is a separate global platform role, not a business Team role.
- Use the starter kit's existing Team and membership/pivot tables as the canonical tenancy records. Extend them with new migrations for AGOS-specific business profile fields or role values.
- Do not create duplicate `Business` or `Membership` tables for the same concepts.
- Customize business onboarding so the Team name and required business profile details are captured. Do not treat an automatically created personal Team as a fully configured AGOS business until onboarding is complete.
- A user may belong to multiple business Teams where authorized. The current Team is a navigation context only; every query, Livewire action, job, import, and report must independently verify membership and authorization.
- Team switching must not permit access to another business's records without membership and permission checks.

### 2.3 Permission matrix

  --------------------------------------------------------------------------------
  Capability        Business Owner        Manager     Bookkeeper /          System
                                                     Finance Staff   Administrator
  ----------------- -------------- -------------- ---------------- ---------------
  Read business                Yes  Yes, assigned    Yes, assigned   No by default
  records and                            business         business 
  reports                                                          

  Create or edit               Yes  Yes, assigned    Yes, assigned              No
  financial records                      business         business 

  Run scenarios                Yes            Yes  As permitted by              No
                                                          business 
                                                    configuration; 
                                                        default no 

  View                         Yes            Yes              Yes              No
  recommendations                                                  

  Manage business              Yes             No               No        Platform
  users and                                                          settings only
  settings                                                         

  Manage technical              No             No               No             Yes
  platform settings                                                

  View business                Yes  No by default    No by default     Operational
  audit events                                                      metadata only;
                                                                      no financial
                                                                        content by
                                                                           default
  --------------------------------------------------------------------------------

The implementation must enforce permissions on the backend. Hiding a
button in the frontend is not sufficient authorization.

## 3. Functional requirements

Priority definitions: **Must** = required for the first complete
demonstration; **Should** = included where feasible within the project
schedule; **Could** = optional enhancement.

### 3.1 Authentication, users, and business isolation

  ------------------------------------------------------------------------------
  ID                Priority          Requirement              Acceptance
                                                               criteria
  ----------------- ----------------- ------------------------ -----------------
  FR-AUTH-01        Must              The system shall allow   Valid credentials
                                      registered users to sign create an
                                      in with valid            authenticated
                                      credentials.             session; invalid
                                                               credentials are
                                                               rejected with a
                                                               safe message.

  FR-AUTH-02        Must              The system shall provide Logout revokes
                                      a logout function.       the active
                                                               session and
                                                               prevents its
                                                               reuse.

  FR-AUTH-03        Must              The system shall support Password
                                      password reset or        replacement
                                      administrator-assisted   invalidates
                                      password replacement.    existing active
                                                               sessions.

  FR-AUTH-04        Must              The system shall use     Session
                                      server-managed sessions  identifiers are
                                      and secure cookie        rotated on login;
                                      attributes.              cookies are
                                                               HttpOnly and
                                                               SameSite;
                                                               deployed HTTPS
                                                               configuration
                                                               uses Secure
                                                               cookies.

  FR-AUTH-05        Must              The system shall expire  A session idle
                                      an idle session after 30 beyond the
                                      minutes.                 configured limit
                                                               requires
                                                               authentication
                                                               again.

  FR-AUTH-06        Must              The system shall enforce Each protected
                                      role-based access        operation checks
                                      control.                 the user's role
                                                               and business
                                                               assignment on the
                                                               backend.

  FR-AUTH-07        Must              The system shall isolate A user cannot
                                      data by business.        read, modify,
                                                               import, or report
                                                               on another
                                                               business's
                                                               records by
                                                               changing an
                                                               identifier in a
                                                               request.

  FR-AUTH-08        Must              The System Administrator Platform
                                      shall have no default    administration
                                      access to business       endpoints expose
                                      financial records.       operational
                                                               settings only
                                                               unless a
                                                               separately
                                                               approved access
                                                               process is
                                                               defined.

  FR-AUTH-09        Must              The system shall         Repeated login
                                      rate-limit login         attempts are
                                      attempts and protect     limited;
                                      state-changing session   state-changing
                                      requests against CSRF.   requests without
                                                               valid CSRF
                                                               protection are
                                                               rejected.

  FR-USER-01        Must              The Business Owner shall Only an
                                      be able to create,       authorized owner
                                      update, deactivate, and  can manage users
                                      assign business users.   for that
                                                               business; changes
                                                               are auditable.

  FR-USER-02        Must              The Business Owner shall Saved settings
                                      be able to maintain      are applied to
                                      business profile and     that business
                                      cash-safety settings.    only.
  ------------------------------------------------------------------------------

### 3.2 Dashboard and cash overview

  -----------------------------------------------------------------------
  ID                Priority          Requirement       Acceptance
                                                        criteria
  ----------------- ----------------- ----------------- -----------------
  FR-DASH-01        Must              The dashboard     The displayed
                                      shall display the amount is
                                      business's        calculated from
                                      current cash      confirmed cash
                                      position.         movements and
                                                        reconciles with
                                                        the underlying
                                                        records.

  FR-DASH-02        Must              The dashboard     The projection is
                                      shall display     generated by the
                                      projected cash    deterministic
                                      for the selected  AGOS calculation
                                      forecast period.  engine.

  FR-DASH-03        Must              The dashboard     Users can view
                                      shall display     inflows,
                                      cash-flow trends. outflows, and
                                                        projected balance
                                                        over the
                                                        supported period.

  FR-DASH-04        Must              The dashboard     Each item
                                      shall show        displays its
                                      upcoming inflows  date, amount,
                                      and outflows.     type, status or
                                                        certainty
                                                        classification,
                                                        and source record
                                                        where available.

  FR-DASH-05        Must              The dashboard     Overdue invoices
                                      shall identify    are listed with
                                      overdue customer  customer, due
                                      collections.      date, outstanding
                                                        amount, and days
                                                        overdue.

  FR-DASH-06        Must              The dashboard     Upcoming bills
                                      shall identify    and scheduled
                                      upcoming expenses expenses are
                                      and supplier      listed with due
                                      obligations.      dates and
                                                        outstanding
                                                        amounts.

  FR-DASH-07        Must              The dashboard     The configured
                                      shall display the threshold is
                                      business's        visible and its
                                      cash-safety       effect on
                                      threshold.        projected cash is
                                                        shown.

  FR-DASH-08        Must              The dashboard     The date is shown
                                      shall identify    when a forecast
                                      the earliest date shortfall exists;
                                      projected cash    otherwise the
                                      may fall below    dashboard
                                      the threshold.    indicates that no
                                                        shortfall was
                                                        detected in the
                                                        selected period.

  FR-DASH-09        Must              The dashboard     The categories
                                      shall distinguish are visibly
                                      confirmed cash,   distinguishable
                                      expected cash,    and are not
                                      uncertain         silently combined
                                      collections, and  into confirmed
                                      optional future   cash.
                                      expenses.         

  FR-DASH-10        Should            The dashboard     The timestamp is
                                      shall show the    shown; failed or
                                      last successful   incomplete
                                      Loyverse          synchronization
                                      synchronization   is not presented
                                      time and          as current data.
                                      stale-data        
                                      status.           
  -----------------------------------------------------------------------

### 3.3 Transactions and recurring records

  -------------------------------------------------------------------------
  ID                Priority          Requirement       Acceptance criteria
  ----------------- ----------------- ----------------- -------------------
  FR-TRX-01         Must              Authorized users  Required fields are
                                      shall be able to  validated; a saved
                                      create income and transaction is
                                      expense           associated with the
                                      transactions.     authenticated
                                                        business.

  FR-TRX-02         Must              Authorized users  Results are
                                      shall be able to  business-scoped and
                                      view, search,     filters return
                                      filter, and open  matching records.
                                      transaction       
                                      details.          

  FR-TRX-03         Must              Authorized users  Changes are
                                      shall be able to  validated and
                                      edit or archive   recorded in the
                                      transactions      audit trail.
                                      according to role 
                                      permissions.      

  FR-TRX-04         Must              Transactions      A transaction can
                                      shall support     be assigned a
                                      categories.       configured
                                                        category; unmapped
                                                        imported categories
                                                        are marked for
                                                        review.

  FR-TRX-05         Should            The system shall  Users can define
                                      support recurring recurrence details
                                      financial         and review
                                      records.          generated or
                                                        upcoming entries
                                                        before they affect
                                                        cash calculations.

  FR-TRX-06         Must              Monetary amounts  No financial total
                                      shall be stored   relies on binary
                                      and calculated    floating-point
                                      using             arithmetic.
                                      fixed-precision   
                                      decimal values.   

  FR-TRX-07         Must              Each transaction  Manually entered,
                                      shall retain its  CSV-imported, and
                                      source and        Loyverse-imported
                                      relevant          records can be
                                      reference         distinguished.
                                      identifiers.      
  -------------------------------------------------------------------------

### 3.4 Customers, suppliers, invoices, and payments

  ------------------------------------------------------------------------
  ID                Priority          Requirement       Acceptance
                                                        criteria
  ----------------- ----------------- ----------------- ------------------
  FR-CRM-01         Must              Authorized users  Customer records
                                      shall be able to  are
                                      create, view,     business-scoped
                                      update, and       and can be linked
                                      archive customer  to invoices and
                                      records.          receivables.

  FR-CRM-02         Must              Authorized users  Supplier records
                                      shall be able to  are
                                      create, view,     business-scoped
                                      update, and       and can be linked
                                      archive supplier  to bills and
                                      records.          payables.

  FR-AR-01          Must              Authorized users  Invoice number,
                                      shall be able to  customer, issue
                                      create customer   date, due date,
                                      invoices.         line items or
                                                        description, and
                                                        totals are
                                                        validated and
                                                        saved.

  FR-AR-02          Must              The system shall  Full and partial
                                      calculate invoice payments update
                                      totals and        the balance
                                      outstanding       correctly; paid,
                                      balances.         partially paid,
                                                        overdue, and
                                                        unpaid states are
                                                        consistent with
                                                        dates and amounts.

  FR-AR-03          Must              Authorized users  A payment is
                                      shall be able to  linked to the
                                      record full or    invoice and
                                      partial customer  customer; the
                                      payments.         outstanding
                                                        balance is
                                                        recalculated.

  FR-AR-04          Must              The system shall  Invoice status and
                                      identify unpaid   due date determine
                                      and overdue       overdue status;
                                      invoices.         the outstanding
                                                        amount is shown.

  FR-AP-01          Must              Authorized users  Supplier, amount,
                                      shall be able to  issue date, due
                                      record supplier   date, and payment
                                      bills and         status are stored
                                      obligations.      and
                                                        business-scoped.

  FR-AP-02          Must              Authorized users  The remaining
                                      shall be able to  payable balance is
                                      record full or    recalculated and
                                      partial supplier  reflected in
                                      payments.         cash-flow
                                                        calculations.

  FR-AP-03          Must              The system shall  Lists show
                                      identify upcoming supplier, due
                                      and overdue       date, outstanding
                                      supplier          amount, and
                                      obligations.      status.

  FR-AR-AP-01       Must              The system shall  Creating an
                                      preserve the      invoice or bill
                                      distinction       alone does not
                                      between an        incorrectly
                                      invoice or bill   increase or
                                      and the actual    decrease confirmed
                                      payment movement. cash.

  FR-AR-AP-02       Should            The system shall  The generated PDF
                                      generate an       reflects the saved
                                      invoice PDF.      invoice data and
                                                        totals; PDF
                                                        failure does not
                                                        alter the invoice.
  ------------------------------------------------------------------------

### 3.5 CSV import

  -----------------------------------------------------------------------
  ID                Priority          Requirement       Acceptance
                                                        criteria
  ----------------- ----------------- ----------------- -----------------
  FR-CSV-01         Must              The system shall  Invalid file
                                      accept UTF-8 CSV  encodings or
                                      files with a      missing headers
                                      header row.       are reported
                                                        before import.

  FR-CSV-02         Must              The system shall  Users can map
                                      provide a         required and
                                      column-mapping    optional source
                                      preview.          columns to AGOS
                                                        fields before
                                                        committing.

  FR-CSV-03         Must              The importer      Rows missing
                                      shall require     required values
                                      date, type,       are rejected with
                                      amount, currency, a row-specific
                                      and a description reason.
                                      or source         
                                      reference.        

  FR-CSV-04         Must              The importer      Ambiguous dates
                                      shall accept ISO  are rejected
                                      8601 dates or an  rather than
                                      explicitly        guessed.
                                      selected          
                                      day-month-year    
                                      format.           

  FR-CSV-05         Must              The importer      Amounts are valid
                                      shall validate    nonnegative
                                      amounts and       decimal strings
                                      currency.         with at most two
                                                        fractional
                                                        digits; non-PHP
                                                        currency is
                                                        rejected for
                                                        review in the
                                                        first release.

  FR-CSV-06         Must              The importer      Duplicate
                                      shall report      external IDs and
                                      exact and         probable
                                      probable          duplicate rows
                                      duplicates before are identified
                                      import.           for user review.

  FR-CSV-07         Must              The importer      Invalid rows do
                                      shall allow valid not silently
                                      rows to be        enter the
                                      committed as a    database; valid
                                      reviewed batch.   rows can be
                                                        committed with an
                                                        import summary.

  FR-CSV-08         Must              The importer      Each rejected row
                                      shall provide a   includes its
                                      downloadable      source row number
                                      row-numbered      and reason.
                                      error report.     

  FR-CSV-09         Must              The system shall  The application
                                      mark forecast     does not invent
                                      history as        opening balances
                                      incomplete when   or represent
                                      imported history  incomplete
                                      is incomplete.    history as
                                                        complete.
  -----------------------------------------------------------------------

### 3.6 Loyverse POS integration

  ------------------------------------------------------------------------------------
  ID                Priority          Requirement        Acceptance criteria
  ----------------- ----------------- ------------------ -----------------------------
  FR-LYV-01         Must              The system shall   Credentials are stored
                                      allow an           server-side and are not
                                      authorized owner   exposed to the browser or
                                      to configure       committed to source control.
                                      Loyverse           
                                      credentials for a  
                                      business.          

  FR-LYV-02         Must              The Loyverse       Requests use the configured
                                      adapter shall      credentials and supported API
                                      retrieve sales     fields.
                                      transactions using 
                                      the Loyverse API.  

  FR-LYV-03         Must              The first-release  AGOS does not create, edit,
                                      integration shall  or delete Loyverse records.
                                      be read-only.      

  FR-LYV-04         Must              Retrieved          Mapped records retain
                                      transactions shall `source_system = loyverse`,
                                      be mapped into the external transaction ID, and
                                      AGOS transaction   synchronization timestamp.
                                      format.            

  FR-LYV-05         Must              The adapter shall  Missing or conflicting
                                      validate required  records are quarantined with
                                      source fields and  a reason and are excluded
                                      financial meaning  from verified cash totals.
                                      before accepting a 
                                      record.            

  FR-LYV-06         Must              The adapter shall  Business ID plus external
                                      prevent duplicate  transaction ID identifies an
                                      imports.           imported transaction; updates
                                                         do not double-count it.

  FR-LYV-07         Must              The system shall   The user can start a sync and
                                      support            view its status, summary, and
                                      user-triggered     last successful
                                      synchronization.   synchronization time.

  FR-LYV-08         Should            The system may     Scheduled sync is enabled
                                      support scheduled  only after API access and
                                      synchronization.   local execution behavior are
                                                         verified.

  FR-LYV-09         Must              The system shall   An incomplete sync is
                                      preserve           reported as incomplete and is
                                      successful pages   not marked fully successful.
                                      and checkpoints    
                                      when a multi-page  
                                      sync is            
                                      interrupted.       

  FR-LYV-10         Must              The system shall   Previously imported records
                                      show a clear error remain available and are
                                      and preserve       labeled with their last
                                      existing data when successful sync time.
                                      Loyverse is        
                                      unavailable.       
  ------------------------------------------------------------------------------------

**Loyverse financial interpretation rules**

-   Only the amount actually paid contributes to confirmed cash; an
    unpaid order total does not.
-   Discounts reduce the paid sales amount.
-   Included tax is part of the receipt total and is not added again.
-   A completed refund is a separate negative cash movement on its
    effective refund date and references the original receipt where
    possible.
-   Split payments are summed to the collected amount and retain their
    payment methods.
-   Unpaid, void, pending, or unconfirmed payments do not increase
    current cash. Expected but uncertain collections remain separately
    labeled in forecasts.
-   If required status, amount, currency, source ID, or transaction date
    is missing, or values conflict beyond PHP 0.01 rounding tolerance,
    the record is quarantined.
-   Non-PHP amounts are rejected for review; the first release performs
    no currency conversion.
-   A source ID plus business ID prevents duplicate cash movements.
    Updated source records replace the normalized representation without
    double-counting.

### 3.7 Cash-flow calculation and forecasting

  -----------------------------------------------------------------------
  ID                Priority          Requirement       Acceptance
                                                        criteria
  ----------------- ----------------- ----------------- -----------------
  FR-CF-01          Must              The backend shall Results reconcile
                                      calculate current with
                                      cash from         independently
                                      verified,         prepared test
                                      confirmed cash    cases.
                                      movements.        

  FR-CF-02          Must              The backend shall Forecast inputs
                                      calculate         and their source
                                      forecast inflows  records can be
                                      and outflows from inspected.
                                      applicable        
                                      financial         
                                      records.          

  FR-CF-03          Must              The forecast      Each item is
                                      shall distinguish classified and
                                      confirmed,        displayed
                                      expected,         according to its
                                      uncertain, and    status; uncertain
                                      optional items.   or optional
                                                        amounts are not
                                                        misrepresented as
                                                        confirmed.

  FR-CF-04          Must              The system shall  The calculation
                                      calculate         is deterministic
                                      projected cash    for the same
                                      over a selected   input records and
                                      short-term        settings.
                                      forecast period.  

  FR-CF-05          Must              The system shall  Threshold
                                      compare projected crossings are
                                      cash against the  identified with
                                      business's        date and amount.
                                      cash-safety       
                                      threshold.        

  FR-CF-06          Must              The system shall  The gap equals
                                      calculate the     the shortfall
                                      cash gap when     relative to the
                                      projected cash    configured
                                      falls below the   threshold at the
                                      threshold.        relevant forecast
                                                        point.

  FR-CF-07          Must              The system shall  The earliest
                                      identify the      crossing date is
                                      earliest          returned when a
                                      projected         shortfall occurs.
                                      shortfall date.   

  FR-CF-08          Must              The calculation   Frontend displays
                                      engine shall run  returned
                                      in backend        calculation
                                      services, not in  results; the AI
                                      frontend          receives verified
                                      components or the outputs only.
                                      AI provider.      

  FR-CF-09          Must              The system shall  Forecasts
                                      disclose          identify missing
                                      incomplete or     history, stale
                                      stale source data POS sync, or
                                      that affects a    other relevant
                                      forecast.         data limitations.
  -----------------------------------------------------------------------

### 3.8 Form-based Scenario Simulator

  -----------------------------------------------------------------------
  ID                Priority          Requirement       Acceptance
                                                        criteria
  ----------------- ----------------- ----------------- -----------------
  FR-SIM-01         Must              The system shall  Users can enter
                                      provide a         hypothetical
                                      form-based        values and submit
                                      Scenario          a scenario
                                      Simulator.        without editing
                                                        actual records.

  FR-SIM-02         Must              The simulator     User can select
                                      shall support a   an invoice and
                                      customer payment  delay its
                                      delay.            expected payment
                                                        by a number of
                                                        days.

  FR-SIM-03         Must              The simulator     User can enter a
                                      shall support a   reduction amount
                                      reduction in      or percentage and
                                      sales.            forecast period.

  FR-SIM-04         Must              The simulator     User can enter
                                      shall support an  the hypothetical
                                      increase in       expense amount
                                      expenses.         and date.

  FR-SIM-05         Must              The simulator     User can select
                                      shall support a   an obligation and
                                      delayed supplier  change its
                                      payment.          hypothetical
                                                        payment date.

  FR-SIM-06         Must              The simulator     User can enter a
                                      shall support a   hypothetical
                                      customer deposit. deposit amount
                                                        and date.

  FR-SIM-07         Must              The simulator     User can enter
                                      shall support a   purchase amount,
                                      proposed          proposed date,
                                      purchase.         payment terms,
                                                        and expected
                                                        related income.

  FR-SIM-08         Must              The simulator     Results show
                                      shall compare the projected cash,
                                      normal forecast   threshold impact,
                                      with the          cash gap, and
                                      hypothetical      earliest
                                      forecast.         shortfall date
                                                        for both
                                                        forecasts.

  FR-SIM-09         Must              Scenario records  Running, editing,
                                      and calculations  or deleting a
                                      shall remain      scenario does not
                                      separate from     change
                                      actual financial  transactions,
                                      records.          invoices, bills,
                                                        or actual cash.

  FR-SIM-10         Should            The system shall  Saved scenarios
                                      allow users to    are
                                      retain and        business-scoped
                                      revisit scenario  and display their
                                      results.          inputs and
                                                        calculated
                                                        outputs.
  -----------------------------------------------------------------------

### 3.9 Purchase-affordability analysis

  -----------------------------------------------------------------------
  ID                Priority          Requirement       Acceptance
                                                        criteria
  ----------------- ----------------- ----------------- -----------------
  FR-PUR-01         Must              The system shall  All required
                                      accept a proposed inputs are
                                      purchase amount,  validated before
                                      date, payment     analysis.
                                      terms, and        
                                      expected related  
                                      income.           

  FR-PUR-02         Must              The system shall  The result
                                      determine whether identifies the
                                      the proposed      relevant
                                      purchase may      projected date,
                                      cause projected   cash gap, and
                                      cash to fall      contributing
                                      below the safety  purchase
                                      threshold.        assumptions.

  FR-PUR-03         Should            The system shall  Users can compare
                                      allow comparison  purchasing
                                      of alternatives.  immediately,
                                                        delaying the
                                                        purchase,
                                                        reducing the
                                                        amount, or
                                                        requesting a
                                                        customer deposit.

  FR-PUR-04         Must              Purchase analysis The analysis does
                                      shall be          not create a real
                                      hypothetical      expense or
                                      until the user    payment.
                                      separately        
                                      records an actual 
                                      transaction.      
  -----------------------------------------------------------------------

### 3.10 Action Center and recommendations

  ------------------------------------------------------------------------
  ID                Priority          Requirement        Acceptance
                                                         criteria
  ----------------- ----------------- ------------------ -----------------
  FR-REC-01         Must              The system shall   At least one
                                      generate           applicable action
                                      rule-based         is produced for
                                      candidate actions  supported risk
                                      from verified      conditions;
                                      financial data.    otherwise the
                                                         system indicates
                                                         that no action
                                                         was identified.

  FR-REC-02         Must              The Action Center  Recommendations
                                      shall prioritize   show priority,
                                      recommendations.   reason, related
                                                         record, and
                                                         relevant amount
                                                         or date when
                                                         available.

  FR-REC-03         Must              A recommendation   Each displayed
                                      shall identify the recommendation
                                      financial records  includes evidence
                                      or calculations    references
                                      supporting it.     resolvable to
                                                         AGOS records or
                                                         calculation
                                                         results.

  FR-REC-04         Must              The system shall   The application
                                      support an         uses a common
                                      AI-provider        internal request
                                      adapter for Groq   and response
                                      or Gemini.         contract
                                                         independent of
                                                         the selected
                                                         provider.

  FR-REC-05         Must              The AI shall       The AI request
                                      receive only       does not provide
                                      verified AGOS      unrestricted
                                      results and        database access.
                                      candidate actions  
                                      needed to explain  
                                      the situation.     

  FR-REC-06         Must              The AI shall       The response is
                                      explain financial  understandable
                                      risk, prioritize   and refers to
                                      candidate actions, supplied evidence
                                      and identify       and limitations.
                                      missing            
                                      information.       

  FR-REC-07         Must              The AI shall not   Those values are
                                      calculate official calculated by
                                      cash balances,     AGOS and passed
                                      invoice balances,  to the AI as
                                      forecasts, or      verified inputs.
                                      scenario results.  

  FR-REC-08         Must              The AI shall not   No such action is
                                      modify financial   exposed through
                                      records, approve   the AI
                                      loans, send        integration.
                                      messages           
                                      automatically, or  
                                      execute payments.  

  FR-REC-09         Must              The system shall   Missing required
                                      validate AI output fields, malformed
                                      against a defined  output, or
                                      response schema    unsupported
                                      and evidence       actions are
                                      references.        rejected.

  FR-REC-10         Must              The system shall   The Action Center
                                      fall back to       remains usable
                                      rule-based         and indicates
                                      recommendations if that the
                                      the AI provider is rule-based result
                                      unavailable or     is being shown.
                                      returns invalid    
                                      output.            

  FR-REC-11         Must              The system shall   The user can
                                      disclose when      identify the
                                      recommendations    relevant data
                                      use incomplete or  limitation.
                                      stale information. 
  ------------------------------------------------------------------------

### 3.11 Financing readiness

  ---------------------------------------------------------------------------
  ID                Priority          Requirement           Acceptance
                                                            criteria
  ----------------- ----------------- --------------------- -----------------
  FR-FIN-01         Must              The system shall      The checklist
                                      assess whether        covers
                                      selected financial    transaction
                                      information is        records, income
                                      available and recent. and expenses,
                                                            invoices,
                                                            supplier
                                                            obligations,
                                                            cash-flow
                                                            reports, and
                                                            selected
                                                            supporting
                                                            documents.

  FR-FIN-02         Must              The system shall      Each missing item
                                      identify missing or   is listed with a
                                      incomplete records.   clear
                                                            explanation.

  FR-FIN-03         Should            The system shall      The report
                                      generate a            summarizes
                                      financing-readiness   available
                                      report.               information,
                                                            missing items,
                                                            and the
                                                            assessment date.

  FR-FIN-04         Must              The system shall not  UI labels and
                                      predict loan approval reports describe
                                      or determine          record readiness
                                      financing             only, not
                                      eligibility.          approval
                                                            likelihood or
                                                            qualification.
  ---------------------------------------------------------------------------

### 3.12 Reports and PDF generation

  ----------------------------------------------------------------------------------
  ID                Priority          Requirement           Acceptance criteria
  ----------------- ----------------- --------------------- ------------------------
  FR-RPT-01         Must              The system shall      Report totals match the
                                      generate cash-flow    approved AGOS report
                                      summary reports.      preview.

  FR-RPT-02         Must              The system shall      Report balances match
                                      generate              the underlying invoice,
                                      accounts-receivable   bill, and payment
                                      and accounts-payable  records.
                                      reports.              

  FR-RPT-03         Must              The system shall      Report shows scenario
                                      generate scenario     inputs and
                                      result reports.       normal-versus-scenario
                                                            results.

  FR-RPT-04         Should            The system shall      Reports show their
                                      generate              source data,
                                      financing-readiness   limitations, and
                                      and recommendation    generation date.
                                      evidence reports.     

  FR-RPT-05         Must              The system shall use  Gotenberg returns a PDF
                                      Gotenberg to convert  with `application/pdf`
                                      AGOS-prepared HTML    content type.
                                      into PDF.             

  FR-RPT-06         Must              The system shall      Financial values are
                                      validate report data  calculated and
                                      before sending it to  authorized by AGOS
                                      Gotenberg.            before document
                                                            rendering.

  FR-RPT-07         Must              The system shall      Failure does not alter
                                      preserve report data  financial records; an
                                      and provide a retry   HTML preview remains
                                      option when PDF       available where
                                      generation fails.     implemented.

  FR-RPT-08         Must              The system shall      Cross-business report
                                      generate PDFs only    requests are rejected.
                                      for records the       
                                      current user is       
                                      authorized to access. 
  ----------------------------------------------------------------------------------

### 3.13 Audit events and operational errors

  ---------------------------------------------------------------------------
  ID                Priority          Requirement        Acceptance criteria
  ----------------- ----------------- ------------------ --------------------
  FR-AUD-01         Must              The system shall   Each event includes
                                      record audit       actor ID, business
                                      events for sign-in ID where applicable,
                                      attempts,          event type, target
                                      permission         type and ID, UTC
                                      changes, imports   time, result, and
                                      and syncs,         request ID.
                                      financial record   
                                      changes, payment   
                                      recording, report  
                                      generation, and    
                                      manual             
                                      corrections.       

  FR-AUD-02         Must              The audit record   The change can be
                                      shall capture      reviewed by an
                                      before-and-after   authorized business
                                      values for changes owner.
                                      to monetary        
                                      amounts.           

  FR-AUD-03         Must              Audit logs shall   Inspection of logs
                                      not store          confirms sensitive
                                      passwords, API     secrets and
                                      tokens, full       unnecessary payloads
                                      provider payloads, are absent.
                                      or unnecessary     
                                      personal           
                                      information.       

  FR-AUD-04         Must              Business owners    Audit queries are
                                      shall be able to   business-scoped and
                                      view their         owner-authorized.
                                      business audit     
                                      events.            

  FR-AUD-05         Must              Operational logs   Operational entries
                                      shall support      use request IDs and
                                      troubleshooting    error metadata
                                      without exposing   without unnecessary
                                      business financial financial values.
                                      content to         
                                      platform operators 
                                      by default.        

  FR-AUD-06         Must              The initial audit  A documented
                                      retention          retention/deletion
                                      configuration      process is applied
                                      shall be 90 days.  to the local
                                                         implementation.
  ---------------------------------------------------------------------------

## 4. External interface requirements

### 4.1 User interface

The Laravel Blade and Livewire interface shall provide responsive pages or views for:

-   Sign-in and session handling
-   Dashboard and cash overview
-   Transactions and categories
-   Customers and suppliers
-   Invoices, payments, bills, receivables, and payables
-   CSV import and import results
-   Loyverse connection and synchronization status
-   Scenario Simulator and purchase-affordability analysis
-   Action Center and recommendation evidence
-   Financing-readiness checklist
-   Reports and PDF preview/download
-   Business settings, users, and audit events according to role

### 4.2 Web routes, Livewire actions, and optional REST API

- Authenticated users access AGOS through Laravel `web.php` routes, Blade layouts, and Livewire components.
- Livewire actions call authorized Laravel application services; the UI does not need duplicate JSON endpoints for every CRUD operation.
- A versioned JSON API (`/api/v1`) may be exposed where required for an external client or a documented integration contract. Example resources include `/api/v1/transactions`, `/api/v1/invoices`, `/api/v1/scenarios`, `/api/v1/recommendations`, `/api/v1/integrations/loyverse`, and `/api/v1/reports`.
- When a JSON API is provided, it shall use documented schemas, standard methods and status codes, business-scoped authorization, validation, predictable error responses, and appropriate authentication.
- External provider HTTP calls shall use backend adapters and never expose provider credentials to the browser.
- The deterministic calculation and business services shall be shared by Livewire views, scheduled commands/jobs, and any optional API endpoints; no duplicate business-rule implementations are permitted.

### 4.3 Loyverse interface

-   Use the official Loyverse API and its supported authentication
    mechanism.
-   Retrieve only the data needed for sales and cash-flow records.
-   Operate in read-only mode.
-   Store source transaction IDs and synchronization timestamps.
-   Validate provider responses before normalizing and saving records.
-   Preserve checkpoints for multi-page synchronization.

### 4.4 AI interface

-   Use either Groq API or Google Gemini API through a provider-neutral
    adapter.
-   Send structured, verified financial values and candidate actions.
-   Require a structured response containing an explanation, ranked
    actions, reasons, estimated cash effects where supported, evidence
    references, and limitations.
-   Validate output before display.
-   Use rule-based fallback when the provider is unavailable or its
    response is invalid.
-   Keep provider API keys on the backend.

### 4.5 Gotenberg interface

- AGOS shall render authorized, validated report data into HTML using Laravel Blade templates.
- The Laravel backend shall submit the HTML via HTTPS and server-side Basic Authentication to **Render-hosted Gotenberg** (Chromium-only image), never directly from the browser.
- Gotenberg shall return a PDF response with `application/pdf` content type; AGOS shall verify the response and report totals.
- The Gotenberg instance has a 512 MiB RAM allocation; configure one active Chromium conversion and at most two queued requests.
- For AGOS, generate PDFs sequentially through one local Laravel **database-queue worker**, with a maximum of two pending report jobs and no Redis dependency. Reject or defer additional work safely.
- Use a 20-second Gotenberg request timeout and a 60-second report job limit. Retry at most once on transient 429/503 responses; do not retry authentication or validation failures.
- Preserve the validated report snapshot and HTML preview for manual retry after failure. A failed PDF conversion shall never modify financial records.
- Configure service URL and Basic Authentication values only through backend environment settings; the Render service has separate secret variables.

### 4.6 File interfaces

**CSV input**

-   UTF-8 encoding and header row
-   Required fields: date, type, amount, currency, and description or
    source reference
-   Optional fields: customer, supplier, category, payment status, and
    external ID
-   ISO 8601 dates or explicitly selected day-month-year format
-   Invalid rows reported with row numbers and reasons

**PDF output**

-   MIME type: `application/pdf`
-   Generated from HTML using Gotenberg
-   Contains only data calculated and authorized by AGOS

## 5. Data and business rules

### 5.1 Data ownership and isolation

Every business-owned record shall be associated with a business
identifier. Backend queries and file access shall be scoped to the
authenticated user's permitted business. The application shall not rely
on client-supplied business IDs as proof of authorization.

### 5.2 Monetary values and dates

-   The default currency is PHP.
-   Monetary values shall use fixed-precision decimal storage and
    arithmetic.
-   Do not use PHP `float` or JavaScript `Number` for authoritative financial totals; use fixed-precision decimal strings and `BigDecimal`.
-   Store timestamps in UTC.
-   Display business-facing dates and times using `Asia/Manila`.
-   The first release shall not perform currency conversion.

### 5.3 Actual records and hypothetical records

Actual transactions, invoices, bills, and payments shall be stored
separately from scenario inputs and scenario results. Scenario execution
shall never create or modify actual financial records.

### 5.4 CSV import rules

-   Reject ambiguous dates rather than guessing.
-   Reject malformed or negative amount values where the import format
    requires nonnegative amount strings.
-   Treat income or expense type as the direction of the movement.
-   Keep unknown categories unmapped for review.
-   Detect duplicate external IDs and probable duplicate rows before
    committing.
-   Do not invent opening balances when imported history is incomplete.

### 5.5 Loyverse reconciliation rules

Apply the financial interpretation rules in FR-LYV-01 through FR-LYV-10.
In particular, only confirmed paid amounts affect current cash; refunds
are negative movements; included taxes are not added twice; and
quarantined records are excluded from verified totals.

### 5.6 Recommendation evidence

Every displayed recommendation shall reference the AGOS records or
calculation results that support it. An AI-generated explanation is not
itself evidence that a financial event exists. The backend shall
validate evidence references before display.

## 6. Error handling and resilience requirements

  ------------------------------------------------------------------------
  Operation              Initial limit from proposal Required behavior on
                                                     failure
  --------------------- ---------------------------- ---------------------
  Loyverse page request   8 seconds per request; 120 Preserve completed
                                seconds per sync job pages and checkpoint;
                                                     report incomplete
                                                     sync without
                                                     publishing an
                                                     unverified total.

  AI recommendation       10 seconds provider limit; Show verified
                           15 seconds overall target rule-based
                                                     recommendation if
                                                     provider times out or
                                                     returns invalid
                                                     output.

  Gotenberg conversion    20 seconds per request; 60 Preserve source
                              seconds per background report data and offer
                                          report job retry; retain HTML
                                                     preview where
                                                     implemented.

  Dashboard and                3 seconds at the 95th Display status and
  scenario calculation          percentile under the retain inputs while
                        proposal's assumed test load the bottleneck is
                                                     diagnosed.
  ------------------------------------------------------------------------

Retry behavior:

-   For safe, idempotent reads, make at most three total attempts: the
    initial call and two retries.
-   Use approximately 1-second and 2-second backoff plus jitter.
-   Retry transient network errors and HTTP 500, 502, 503, and 504.
-   For HTTP 429, a background job shall honor `Retry-After` up to 30
    seconds before re-queuing; an interactive request shall show status
    or fallback.
-   Do not automatically retry HTTP 400, 401, 403, or 422.
-   Do not automatically retry writes or PDF requests unless an
    idempotency key or equivalent duplicate safeguard exists.
-   After repeated provider failures, pause calls for 60 seconds and
    perform a single recovery probe.
-   A failed synchronization page shall not mark the entire sync
    complete.

For a local academic implementation, these values are initial
configuration targets and must be verified through tests. They are not
provider guarantees.

## 7. Quality and security constraints relevant to functionality

Although this document focuses on functional requirements, the following
constraints affect whether the functions are acceptable:

1.  Use Eloquent ORM or parameter-bound Laravel query builder statements.
2.  Validate all user input and external API responses.
3.  Store passwords using a modern salted one-way password-hashing
    algorithm.
4.  Store API credentials outside source code and do not commit `.env`
    files.
5.  Use server-side authorization for every protected operation.
6.  Apply CSRF protection to state-changing session requests and
    use Laravel CSRF middleware for web/Livewire requests. Restrict CORS only if a separate JSON API is exposed.
7.  Keep secrets and unnecessary personal or financial information out
    of logs.
8.  Use versioned Laravel migrations and test migration/rollback procedures.
9.  Keep the Laravel service-layer calculation engine authoritative for all official financial values.
10. Use separate adapters for Loyverse, AI, and Gotenberg.

## 8. Acceptance and verification

The system shall be evaluated using unit, integration,
interface/contract, failure, authorization, and acceptance tests.

### 8.1 Required functional test groups

-   Authentication, idle timeout, logout, password reset, and session
    invalidation
-   Role permissions and cross-business access rejection
-   Transaction create, update, archive, and category behavior
-   Invoice totals, partial payments, outstanding balances, and overdue
    detection
-   Supplier bills, partial payments, and payable balances
-   CSV mapping, ambiguous dates, invalid amounts, duplicates, partial
    batches, and error reports
-   Loyverse paid, refunded, discounted, taxed, split-payment,
    missing-field, duplicate, and updated receipt cases
-   Cash calculations, forecast classifications, threshold crossing,
    cash gap, and earliest shortfall date
-   Scenario calculations and proof that actual records remain unchanged
-   Purchase-affordability alternatives
-   Rule-based recommendations, evidence references, AI schema
    validation, and fallback behavior
-   Financing-readiness completeness checks and correct non-approval
    wording
-   PDF totals, generated content, timeout, and retry behavior
-   Audit event completeness and secret redaction

### 8.2 Provisional performance acceptance targets

The proposal assumes a test load of 50 registered businesses and 20
concurrent users, with representative 30-day forecasts and up to 500 POS
receipts in an import.

  -----------------------------------------------------------------------
  Operation                                            Provisional target
  ------------------------------ ----------------------------------------
  Dashboard or scenario results          3 seconds at the 95th percentile
  using locally stored data      

  User-requested recommendation                                15 seconds
  including fallback             

  500-receipt background sync                                 120 seconds
  -----------------------------------------------------------------------

These are proposed acceptance targets to test on the development
computer, not measured results or service commitments.

### 8.3 Definition of functional completion

A requirement is complete when:

-   Its expected behavior is implemented.
-   Its input validation and authorization are implemented.
-   Relevant unit and integration tests pass.
-   External contracts and failure behavior are tested where applicable.
-   Financial results reconcile with independently prepared expected
    values.
-   The UI communicates errors, stale data, incomplete data, and
    fallback states clearly.
-   The feature does not violate business-level data isolation or the
    separation between actual and hypothetical records.

## 9. Implementation baseline and traceability

The AGOS business functions, user roles, financial calculation rules, external integrations, audit requirements, and acceptance criteria are defined in this FRS. The implementation baseline is **Laravel 13 + Livewire 4 + PostgreSQL** for local development.

- One Laravel modular monolith provides the application, UI, and server-side business logic.
- Livewire single-file components and Blade provide the UI; Chart.js provides charts.
- Eloquent models and Laravel database migrations manage persistence.
- `brick/math` `BigDecimal` (or equivalently tested decimal-safe arithmetic) provides fixed-precision application arithmetic.
- Laravel's official Livewire starter kit is configured with Teams support. The generated Team is the AGOS Business; its owner and membership roles are mapped to AGOS roles without duplicate tenancy tables.
- Single-file Livewire components are the default for simple and medium-sized UI components; complex components may use multi-file or class-based formats.
- Laravel session authentication, policies, authorization middleware, and Team membership checks supply the application foundation.
- Laravel HTTP client and dedicated adapters implement read-only Loyverse sync, AI explanations, and PDF generation.
- **Gotenberg remains hosted on Render**, with Basic Authentication and 512 MiB resource constraints. AGOS and its database stay local.
- The UI uses server-side Livewire actions; `/api/v1` REST endpoints are built only where needed by a documented contract, without duplicating business services.

The functional requirement identifiers and acceptance criteria remain in effect. Provider-specific API fields and account access must be verified from current documentation during implementation; unsupported capabilities must not be assumed.
