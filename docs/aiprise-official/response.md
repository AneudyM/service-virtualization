# Response

Every verification—whether KYC or KYB—returns a standardized, structured response.
This response includes the final decision, the status of each check, extracted data, warning codes, and metadata needed for compliance and workflow automation.

AiPrise aggregates results across ID verification, biometrics, fraud checks, AML screening, document analysis, and any additional information submitted for the session.

## High-Level Summary of Included Sections

A verification response may include the following sections depending on your template configuration:

* KYC Checks
* Government Database Checks
* AML Screening
* Fraud Insights
* Biometric Checks (Liveness + Face Match)
* Additional Information Checks
* Custom Template Logic

AiPrise automatically compiles these into one streamlined response object.

## Response Schema Overview

<Table align={["left","left","left"]}>
  <thead>
    <tr>
      <th>
        Key
      </th>

      <th>
        Value
      </th>

      <th>
        Presence
      </th>
    </tr>
  </thead>

  <tbody>
    <tr>
      <td>
        **aiprise\_summary**
      </td>

      <td>
        An overall summary of the response. Contains the final verification result.
      </td>

      <td>
        Present
      </td>
    </tr>

    <tr>
      <td>
        **status**
      </td>

      <td>
        The run status of the verification.
      </td>

      <td>
        Present
      </td>
    </tr>

    <tr>
      <td>
        **status\_reasons**
      </td>

      <td>
        If for some reason, the verification failed, this populates the reasons.
      </td>

      <td>
        Optional
      </td>
    </tr>

    <tr>
      <td>
        **id\_info**
      </td>

      <td>
        Contains all the information extracted from the identity document.
      </td>

      <td>
        Optional
      </td>
    </tr>

    <tr>
      <td>
        **face\_match\_info**
      </td>

      <td>
        Contains information about the face match between selfie and the identity document.
      </td>

      <td>
        Optional
      </td>
    </tr>

    <tr>
      <td>
        **face\_liveness\_info**
      </td>

      <td>
        Contains information about user liveness.
      </td>

      <td>
        Optional
      </td>
    </tr>

    <tr>
      <td>
        **aml\_info**
      </td>

      <td>
        Contains AML checks of the user.
      </td>

      <td>
        Optional
      </td>
    </tr>

    <tr>
      <td>
        **additional\_info**
      </td>

      <td>
        Contains information about checks run on additional\_info
      </td>

      <td>
        Optional
      </td>
    </tr>

    <tr>
      <td>
        **fraud\_insights**
      </td>

      <td>
        Contains fraud insights of the user.
      </td>

      <td>
        Optional
      </td>
    </tr>

    <tr>
      <td>
        **client\_reference\_id**
      </td>

      <td>
        An ID you have associated with your verification request.
      </td>

      <td>
        Present
      </td>
    </tr>

    <tr>
      <td>
        **verification\_session\_id**
      </td>

      <td>
        Verification Session ID (unique to every verification session).
      </td>

      <td>
        Present
      </td>
    </tr>

    <tr>
      <td>
        **template\_id**
      </td>

      <td>
        The template ID against which verification was run.
      </td>

      <td>
        Present
      </td>
    </tr>

    <tr>
      <td>
        **environment**
      </td>

      <td>
        In the sandbox, returns `SANDBOX`, in production,  returns `null`
      </td>

      <td>
        Optional
      </td>
    </tr>

    <tr>
      <td>
        **created\_at**
      </td>

      <td>
        Creation time of the verification session.

        Stored as Unix timestamp in milliseconds.
      </td>

      <td>
        Present
      </td>
    </tr>
  </tbody>
</Table>

***

## Response Properties Explained

Every AiPrise verification response returns a structured JSON object that contains the **final decision**, **workflow status**, and **detailed results for each verification check** performed.

This section explains each top-level property and its purpose.

***

### `aiprise_summary`

Provides the **final verification decision** for the user or business.

#### Structure

```json
aiprise_summary: {
  verification_result: "APPROVED | DECLINED | REVIEW | UNKNOWN"
}
```

#### Verification Results

| Result     | Description                                        |
| ---------- | -------------------------------------------------- |
| `APPROVED` | Verification completed successfully.               |
| `DECLINED` | Verification completed but failed.                 |
| `REVIEW`   | Verification completed and requires manual review. |
| `UNKNOWN`  | Verification did not start or failed unexpectedly. |

***

### `status`

Represents the **execution state** of the verification workflow.

#### Possible Values

| Status        | Description                                         |
| ------------- | --------------------------------------------------- |
| `NOT_STARTED` | Verification has not begun (preconditions pending). |
| `SUBMITTED`   | Verification request accepted but not yet running.  |
| `RUNNING`     | Verification is actively in progress.               |
| `PENDING`     | Waiting for third-party or registry responses.      |
| `FAILED`      | Verification failed. Refer to `status_reasons`.     |
| `COMPLETED`   | Verification workflow completed successfully.       |

***

### `status_reasons`

Returned **only when `status = FAILED`**.

Provides structured error details explaining why the verification failed.

**Example**

```json
status_reasons: [
  {
    "code": "API_DATA_REQUIREMENTS_NOT_MET",
    "message": "Invalid user data. identity_number_type missing."
  }
]
```

<Callout icon="📘" theme="info">
  **Reference:** See the full list in [Status Error Codes](https://docs.aiprise.com/docs/error-codes)
</Callout>

***

### `id_info`

Contains information extracted and validated from the **identity document**.

#### Key Fields

| Field             | Description                                                             |
| ----------------- | ----------------------------------------------------------------------- |
| `result`          | Result of ID verification (`APPROVED`, `DECLINED`, `REVIEW`, `UNKNOWN`) |
| `status`          | Processing status (`COMPLETED`, `FAILED`, `PENDING`)                    |
| `warnings`        | Non-blocking issues                                                     |
| `status_reasons`  | Present only if status is `FAILED`                                      |
| `id_type`         | Detected document type                                                  |
| `id_number`       | Document number                                                         |
| `personal_number` | Secondary identifier (if applicable)                                    |
| `id_issue_date`   | Issue date (YYYY-MM-DD)                                                 |
| `id_expiry_date`  | Expiry date (YYYY-MM-DD)                                                |

***

#### Personal Details

Extracted directly from the document:

* `first_name`
* `middle_name`
* `last_name`
* `second_last_name` *(LATAM only)*
* `full_name`
* `birth_date`
* `gender`
* `nationality` / `nationality_code`
* `issue_country` / `issue_country_code`

***

#### Address Extraction

```json
address: {
  full_address,
  parsed_address: {
    address_street_1,
    address_city,
    address_state,
    address_country,
    address_zip_code
  }
}
```

***

### Document Details

Raw extracted data:

* `mrz_data`
* `barcode_data`
* `ocr_data`

***

#### Registry & Government Lookups

```json
lookup_details.lookup_list[]
```

Each lookup includes:

* Source name
* Lookup type (e.g. `AADHAAR`, `PAN`, `BVN`)
* Result, status, warnings
* Raw lookup data

***

#### Field Matching Information

`field_info` indicates whether extracted fields were **cross-verified** against authoritative sources.

```json
field_info: {
  birth_date: { matched: true },
  id_number: { unmatched: true }
}
```

***

### `face_match_info`

Compares the selfie against the ID photo.

| Field              | Description         |
| ------------------ | ------------------- |
| `result`           | Match result        |
| `face_match_score` | Score out of 100    |
| `status`           | Processing state    |
| `warnings`         | Non-blocking alerts |

***

### `face_liveness_info`

Confirms the user is **physically present** during capture.

| Field    | Description                                  |
| -------- | -------------------------------------------- |
| `result` | Liveness result                              |
| `status` | Processing state                             |
| `source` | Present only when in-screen liveness is used |

***

### `aml_info`

Contains results of **AML screening** across sanctions, PEPs, and adverse media.

#### Summary Fields

* `result`
* `status`
* `num_hits`
* `warnings`

#### Entity Hits

Each entity hit includes:

* Entity type (PERSON / COMPANY)
* Match confidence scores
* AML hit types (SANCTION, PEP, ADVERSE\_MEDIA, etc.)
* Source metadata and evidence

***

### `additional_info`

Results for **additional checks** provided in the request (documents, identifiers, or previous sessions).

#### Example Structure

```json
{
  additional_info_type,
  additional_info_response_type,
  data
}
```

Supported types include:

* Address proof
* Bank statements
* Source of funds
* Tax IDs
* Business documents
* Fraud documents

Each response embeds:

* ID checks
* Address verification
* Document insights
* Risk, trust, and metadata indicators

***

### `fraud_insights`

Aggregated fraud signals collected during the workflow.

Includes insights from:

* **IP & Network**
* **Device & Browser**
* **Email reputation**
* **Phone intelligence**
* **Geolocation consistency**

Each insight follows the same structure:

* `result`
* `status`
* `warnings`
* `risk indicators`

***

### Identifiers & Metadata

| Field                     | Description                            |
| ------------------------- | -------------------------------------- |
| `client_reference_id`     | Your internal reference ID             |
| `verification_session_id` | Unique verification session identifier |
| `template_id`             | Verification template used             |
| `environment`             | `SANDBOX` (sandbox only)               |
| `created_at`              | Unix timestamp (milliseconds)          |

***

## Sample Verification Response

<br />

```json
{
    "aiprise_summary": {
        "verification_result": "APPROVED"
    },
    "aml_info": {
        "entity_hits": [],
        "num_hits": 0,
        "result": "APPROVED",
      	"status": "COMPLETED"
    },
    "client_reference_id": null,
    "created_at": 1681751214017,
    "face_match_info": {
        "face_match_score": 98.59,
        "result": "APPROVED",
      	"status": "COMPLETED"
    },
    "face_liveness_info": {
        "result": "APPROVED",
      	"status": "COMPLETED"
    },
    "id_info": {
        "address": {
            "full_address": "TEST_FULL_ADDRESS",
            "parsed_address": {
                "address_city": "TEST_CITY",
                "address_country": "USA",
                "address_state": "TEST_STATE",
                "address_street_1": "TEST_ADDRESS_STREET_1",
                "address_street_2": "TEST_ADDRESS_STREET_2",
                "address_zip_code": "TEST_ZIP_CODE"
            }
        },
        "birth_date": "1990-01-31",
        "document_details": {
            "ocr_data": {
                "Date of Birth": "1990-01-31",
                "Document Country": "MX",
                "Document Number": "TEST123",
                "Driver License Category": true,
                "Driver License Category From": "2019-10-06",
                "Driver License Category Until": "2025-10-05",
                "Expiry Date": "2030-12-31",
                "First Name": "TEST_FIRST_NAME",
                "Gender": "M",
                "Issue Date": "2015-12-31",
                "Issue Number": "1523",
                "Issued By": "ISSUER",
                "Last Name": "TEST_FIRST_NAME",
                "Nationality": "TEST_COUNTRY",
                "Place of Birth": "MADRID",
                "Year of Birth": "1990"
            },
            "mrz_data": {
                "Date of Birth": "1990-01-31",
                "Document Country": "MX",
                "Document Number": "TEST123",
            },
            "barcode_data": {
                "Date of Birth": "1990-01-31",
                "Document Country": "MX",
                "Document Number": "TEST123",
            },
        },
        "first_name": "TEST_FIRST_NAME",
        "gender": "M",
        "id_expiry_date": "2030-12-31",
        "id_issue_date": "2015-12-31",
        "id_number": "TEST123",
        "id_type": "DRIVER_LICENSE",
        "issue_country": "Mexico",
        "issue_country_code": "MX",
        "last_name": "TEST_LAST_NAME",
        "nationality": "TEST_COUNTRY",
        "result": "APPROVED",
      	"status": "COMPLETED"
    },
    "fraud_insights": {
      "email_insights": [
        {
          "breaches": [
                {
                  "breach_date": "2019-10-16",
                  "domain_name": null,
                  "platform_name": "PDL"
                },
      	],
      	"domain_info": {
            "company_name": "Google LLC",
            "disposable": false,
            "domain_name": "gmail.com",
            "free_provider": true,
            "registered": true,
            "top_level_domain": ".com"
      	},
      	"email": "test@gmail.com",
        "email_tenure": 4.49,
        "first_breach": "2019-10-16",
        "is_breached": true,
        "last_breach": "2023-01-25",
        "no_of_breaches": 1,
        "persons": [
            {
              "addresses": [
                {
                  "address_city": "Singapore",
                  "address_country": "SGP",
                  "address_line_1": "Room 1111, Level 1, Block 56, Hall 11",
                  "address_line_2": "20 Nanyang Avenue",
                  "address_state": null,
                  "last_seen": null,
                  "latitude": "1.35494",
                  "longitude": "103.68653",
                  "postal_code": "639809",
                  "valid_since": "2023-09-27T00:00:00Z"
                }
              ],
              "date_of_births": null,
              "email_address": {
                "last_seen": null,
                "valid_since": "2021-01-21T00:00:00Z",
                "value": "test@gmail.com"
              },
              "genders": null,
              "ip_addresses": [
                {
                  "last_seen": null,
                  "valid_since": "2023-09-27T00:00:00Z",
                  "value": "111.22.333.44"
                }
              ],
              "names": [
                {
                  "last_seen": null,
                  "valid_since": "2022-09-08T00:00:00Z",
                  "value": "John Smith"
                },
                {
                  "last_seen": null,
                  "valid_since": "2023-09-27T00:00:00Z",
                  "value": "Jason Smith"
                }
              ],
              "national_id": null,
              "phone_number": null
            }
          ],
          "result": "FOUND",
          "social_profiles": [
            {
              "account_id": null,
              "last_seen": null,
              "name": null,
              "photo": null,
              "platform": "apple",
              "registered": false
            }
          ],
          "status": "COMPLETED"
        }
      ],
      "phone_insights": [
        {
          "country": "VN",
          "current_carrier": "Mobile Viettel",
          "original_carrier": "Mobile Viettel",
          "phone_active": "YES",
          "phone_disposable": false,
          "phone_number": "9422131119",
          "phone_type": "MOBILE",
          "phone_valid": true,
          "result": "FOUND",
          "social_profiles": [
            {
              "account_id": null,
              "last_seen": null,
              "name": null,
              "photo": null,
              "platform": "google",
              "registered": false
            },
          ],
          "status": "COMPLETED"
        }
      ],
      "device_insights": {
          "bot_status": false,
          "browser_full_version": "124.0.6367",
          "browser_major_version": "124",
          "browser_name": "Chrome Mobile WebView",
          "device": "Infinix X6817",
          "incognito": false,
          "os": "Android",
          "os_version": "12",
          "privacy_settings": false,
          "result": "UNKNOWN",
          "status": "COMPLETED",
          "user_agent": "Mozilla/5.0 (Linux; Android 12; Infinix X6817 Build/SP1A.210812.016; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/124.0.6367.180 Mobile Safari/537.36"
      },
      "ip_insights": {
        "internet_service_provider": "MTN NIGERIA Communication limited",
        "ip_address": "102.88.84.127",
        "location": {
            "city": "Lagos",
            "country": "Nigeria",
            "country_code": "NG",
            "geo_name_id": null,
            "latitude": 6.4474,
            "longitude": 3.3903,
            "postal_code": null,
            "region": "Lagos",
            "timezone": "Africa/Lagos"
        },
        "result": "UNKNOWN",
        "section_id": "fbe49ded-f23a-4fe4-9cf9-de18385abedd",
        "status": "COMPLETED",
        "unique_hash": "81tVVjQVv8d7oCPb0Zte",
        "vpn": false
      },
      "result": "UNKNOWN",
      "section_id": "9e7e4987-5b89-4e26-b06b-b1ee5a560205",
      "status": "COMPLETED"
    },
    "status": "COMPLETED",
    "template_id": "5fd6dddd-4bb1-4d31-8bd7-27801a176c8f",
    "verification_session_id": "2479f0221-517e-4f60-8dff-14a906098ad1"
}
```