# API Endpoints

Endpoints for running, retrieving, and managing KYC verification workflows

## API Endpoints for User Verification

AiPrise uses one core endpoint to run all types of user verification workflows.
This includes:

* Document KYC (passport, ID card, driver’s license, etc.)
* One-Click KYC (registry lookups / government checks)
* Biometric checks (face match, liveness)
* AML screening
* Fraud checks (email, phone, device)
* Address verification
* Custom workflows (risk scoring, questionnaires, advanced flows)

<Callout icon="📘" theme="info">
  All verification behavior is controlled by the template you pass in the request.
  Each `template_id` maps to a workflow that defines:

  * Which checks run
  * In what order
  * With what risk and decision logic

  To learn more about templates, see [Template Configuration](https://docs.aiprise.com/docs/templates)
</Callout>

***

## Core Endpoints

<Table align={["left","left"]}>
  <thead>
    <tr>
      <th>
        Endpoint
      </th>

      <th>
        Description
      </th>
    </tr>
  </thead>

  <tbody>
    <tr>
      <td>
        **POST /run\_user\_verification**
      </td>

      <td>
        This endpoint takes in all data required to run a user verification workflow and returns a verification result.

        You can run the API [here](https://docs.aiprise.com/reference/post_verify-run-user-verification).
      </td>
    </tr>

    <tr>
      <td>
        **POST /get\_user\_verification\_url**
      </td>

      <td>
        Use this endpoint to get verification URL associated with a `template_id`. Note that not every template has AiPrise SDK associated with them. Contact AiPrise if you want to enable SDK for a particular template.

        You can run the API [here](https://docs.aiprise.com/reference/post_verify-get-user-verification-url).
      </td>
    </tr>

    <tr>
      <td>
        **POST /resume\_user\_verification**
      </td>

      <td>
        Some workflows can pause in the middle and requires more user input data. This endpoint takes in the additional user input data and resumes the verification workflow.

        Most verification workflows will never need to use this endpoint.

        You can run the API [here](https://docs.aiprise.com/reference/post_verify-resume-user-verification).
      </td>
    </tr>

    <tr>
      <td>
        **GET /get\_user\_verification\_result/ \{session\_id}**
      </td>

      <td>
        If you want to fetch a verification result in the future, you can use this endpoint. It only requires a`verification_session_id`.

        You can run the API [here](https://docs.aiprise.com/reference/get_verify-get-user-verification-result-verification-session-id).
      </td>
    </tr>

    <tr>
      <td>
        **GET /get\_user\_data\_from\_request/ \{session\_id}**
      </td>

      <td>
        If you want to fetch user input data for a `verification_session_id`, you can use this endpoint.

        You can run the API [here](https://docs.aiprise.com/reference/get_verify-get-user-data-from-request-verification-session-id).
      </td>
    </tr>

    <tr>
      <td>
        **GET /get\_additional\_user\_info\_from\_request/ \{session\_id}**
      </td>

      <td>
        Use this endpoint to get additional input data given by the user.

        You can run the API [here](https://docs.aiprise.com/reference/get_verify-get-additional-user-info-from-request-verification-session-id).
      </td>
    </tr>

    <tr>
      <td>
        **POST /update\_user\_verification\_result**
      </td>

      <td>
        Use this endpoint to update the result of a verification session.

        You can run the API [here](https://docs.aiprise.com/reference/post_verify-update-user-verification-result)
      </td>
    </tr>

    <tr>
      <td>
        **GET /get\_case\_activity/\{session\_id}**
      </td>

      <td>
        Use this endpoint to get case activity of a verification session.

        You can run the API [here](https://docs.aiprise.com/reference/get_verify-get-case-activity-verification-session-id)
      </td>
    </tr>
  </tbody>
</Table>