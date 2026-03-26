# Get user verification url

Endpoint used to get user verification url

# OpenAPI definition

```json
{
  "openapi": "3.0.0",
  "info": {
    "title": "AiPrise APIs",
    "description": "AiPrise API documentation",
    "version": "1.0"
  },
  "servers": [
    {
      "url": "https://api-sandbox.aiprise.com/api/v1/",
      "description": "Sandbox server"
    },
    {
      "url": "https://api.aiprise.com/api/v1/",
      "description": "Production server"
    }
  ],
  "tags": [
    {
      "name": "KYC > Quick Start",
      "description": "One-shot user verification. No profile needed. Get User Verification URL may create a profile depending on template configuration."
    }
  ],
  "paths": {
    "/verify/get_user_verification_url": {
      "post": {
        "tags": [
          "KYC > Quick Start"
        ],
        "summary": "Get user verification url",
        "description": "Endpoint used to get user verification url",
        "requestBody": {
          "description": "User verification request with redirect URL",
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "$ref": "#/components/schemas/UserVerificationUrlRequest"
              }
            },
            "application/xml": {
              "schema": {
                "$ref": "#/components/schemas/UserVerificationUrlRequest"
              }
            },
            "application/x-www-form-urlencoded": {
              "schema": {
                "$ref": "#/components/schemas/UserVerificationUrlRequest"
              }
            }
          }
        },
        "responses": {
          "200": {
            "description": "Successful operation",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/UserVerificationUrlResponse"
                }
              },
              "application/xml": {
                "schema": {
                  "$ref": "#/components/schemas/UserVerificationUrlResponse"
                }
              }
            }
          },
          "400": {
            "description": "Bad Request"
          }
        },
        "callbacks": {
          "result": {
            "{$request.body#/callback_url}": {
              "post": {
                "requestBody": {
                  "content": {
                    "application/json": {
                      "schema": {
                        "$ref": "#/components/schemas/UserVerificationResponseV2"
                      }
                    }
                  }
                },
                "responses": {
                  "200": {
                    "description": "OK"
                  }
                }
              }
            }
          }
        }
      }
    }
  },
  "components": {
    "schemas": {
      "Address": {
        "type": "object",
        "properties": {
          "address_street_1": {
            "type": "string",
            "example": "222 Palm Street",
            "description": "Address street 1"
          },
          "address_street_2": {
            "type": "string",
            "example": null,
            "description": "Address street 2"
          },
          "address_city": {
            "type": "string",
            "example": "Santa Monica",
            "description": "Address city"
          },
          "address_state": {
            "type": "string",
            "example": "CA",
            "description": "Address state or region"
          },
          "address_zip_code": {
            "type": "string",
            "example": "93251",
            "description": "Address zip code"
          },
          "address_country": {
            "type": "string",
            "example": "US",
            "description": "2-letter address country code"
          }
        }
      },
      "ExtractedAddress": {
        "type": "object",
        "properties": {
          "full_address": {
            "type": "string",
            "example": "222 Palm Street, Santa Monica, CA - 93251, US"
          },
          "parsed_address": {
            "type": "object",
            "properties": {
              "address_street_1": {
                "type": "string",
                "example": "222 Palm Street",
                "description": "Address street 1"
              },
              "address_street_2": {
                "type": "string",
                "example": null,
                "description": "Address street 2"
              },
              "address_city": {
                "type": "string",
                "example": "Santa Monica",
                "description": "Address city"
              },
              "address_state": {
                "type": "string",
                "example": "CA",
                "description": "Address state or region"
              },
              "address_zip_code": {
                "type": "string",
                "example": "93251",
                "description": "Address zip code"
              },
              "address_country": {
                "type": "string",
                "example": "US",
                "description": "2-letter address country code"
              },
              "address_type": {
                "type": "string",
                "example": "home",
                "description": "Address type"
              }
            }
          },
          "source": {
            "type": "array",
            "items": {
              "type": "object",
              "properties": {
                "type": {
                  "type": "string"
                },
                "state": {
                  "type": "string"
                },
                "status": {
                  "type": "string"
                },
                "file_number": {
                  "type": "string"
                }
              }
            },
            "description": "Source information for the address",
            "properties": {
              "type": {
                "type": "string"
              },
              "state": {
                "type": "string"
              },
              "status": {
                "type": "string"
              },
              "file_number": {
                "type": "string"
              }
            }
          },
          "additional_info": {
            "type": "object",
            "description": "Additional information about the address (risk indicators, deliverability, etc.)",
            "additionalProperties": {}
          }
        }
      },
      "AiPriseResponseSummaryV2": {
        "type": "object",
        "properties": {
          "verification_result": {
            "$ref": "#/components/schemas/AiPriseResultStatusV2"
          }
        }
      },
      "OutputIdentity": {
        "type": "object",
        "properties": {
          "identity_country_code": {
            "type": "string",
            "example": "US",
            "description": "A 2-letter ISO country code of the identity number or identity document being submitted."
          },
          "identity_number_type": {
            "$ref": "#/components/schemas/IdentityNumberType"
          },
          "identity_number": {
            "type": "string",
            "example": null,
            "description": "The identity number associated with the person of type identity_number_type."
          },
          "identity_document_type": {
            "$ref": "#/components/schemas/IdentityDocumentType"
          },
          "identity_document_front": {
            "type": "string",
            "example": "S3_URL",
            "description": "A S3 url of the front of identity document."
          },
          "identity_document_back": {
            "type": "string",
            "example": "S3_URL",
            "description": "A S3 url of the back of identity document."
          }
        }
      },
      "IdentityDocumentType": {
        "type": "string",
        "nullable": true,
        "example": null,
        "enum": [
          "PASSPORT",
          "DRIVER_LICENSE",
          "NATIONAL_ID",
          "PAN_CARD",
          "ID_CARD",
          "VOTER_ID_CARD",
          "RESIDENT_CARD",
          "GHANA_CARD",
          "GHANA_SSNIT_CARD",
          "KENYA_ALIEN_CARD",
          "COLOMBIA_PPT"
        ],
        "description": "An identity document type. Only one of identity_number or identity_document_type must be provided."
      },
      "IdentityNumberType": {
        "type": "string",
        "nullable": true,
        "example": null,
        "enum": [
          "SSN9",
          "SSN4",
          "DRIVER_LICENSE",
          "PASSPORT",
          "NATIONAL_ID",
          "VOTER_ID",
          "BVN",
          "NIN",
          "NIN_SLIP",
          "SSNIT",
          "TAX_ID",
          "KENYA_KRA_PIN",
          "ALIEN_CARD",
          "RESIDENT_ID",
          "COLOMBIA_PPT_NUMBER",
          "GHANA_CARD_NUMBER"
        ],
        "description": "The type of identity number being submitted. Only one of identity_number_type and identity_document_type must be filled."
      },
      "AdditionalInfo": {
        "type": "array",
        "items": {
          "$ref": "#/components/schemas/IndividualAdditionalInfo"
        },
        "description": "A list of additional information."
      },
      "IndividualAdditionalInfo": {
        "type": "object",
        "properties": {
          "additional_info_type": {
            "type": "string",
            "description": "The type of additional data.",
            "example": null,
            "enum": [
              "KENYA_KRA_PIN",
              "BANK_STATEMENT_DOCUMENT",
              "OPERATING_LICENSE_DOCUMENT",
              "SOURCE_OF_FUNDS_DOCUMENT",
              "BUSINESS_REGISTRATION_DOCUMENT",
              "VISA_DOCUMENT",
              "USER_SELFIE",
              "GENDER",
              "ADDRESS_PROOF_DOCUMENT",
              "NIGERIA_BVN_NUMBER",
              "TAX_IDENTIFICATION_NUMBER",
              "PREVIOUS_VERIFICATION_SESSION_ID",
              "FRAUD_CHECK_DOCUMENT",
              "BANK_ACCOUNT_DETAILS",
              "TAX_CERTIFICATE",
              "OTHER"
            ]
          },
          "additional_info_data": {
            "oneOf": [
              {
                "type": "string",
                "description": "The additional data as a string."
              },
              {
                "type": "object",
                "description": "The additional data as an object. For file-based types, may include file_data, file_uuid, and document_input_title.",
                "properties": {
                  "file_data": {
                    "type": "string",
                    "description": "The file content as base64 or S3 URL."
                  },
                  "file_uuid": {
                    "type": "string",
                    "description": "The unique identifier of the file."
                  },
                  "document_input_title": {
                    "type": "string",
                    "nullable": true,
                    "description": "An optional unique identifier for the document input, if provided during document check."
                  }
                }
              }
            ]
          }
        }
      },
      "PartialUserData": {
        "type": "object",
        "properties": {
          "first_name": {
            "type": "string",
            "description": "The person's first name",
            "example": "John"
          },
          "middle_name": {
            "type": "string",
            "description": "The person's middle name"
          },
          "last_name": {
            "type": "string",
            "description": "The person's last name",
            "example": "Smith"
          },
          "date_of_birth": {
            "type": "string",
            "example": "1984-01-21",
            "description": "The person's date of birth in YYYY-MM-DD format"
          },
          "phone_number": {
            "type": "string",
            "example": "+13334445555",
            "description": "The person's phone number. Prefer phone number in E.164 format"
          },
          "email_address": {
            "type": "string",
            "example": "johnsmith@aiprise.com",
            "description": "The person's email address"
          },
          "ip_address": {
            "type": "string",
            "example": null,
            "description": "The person's ip address"
          },
          "address": {
            "$ref": "#/components/schemas/Address"
          }
        }
      },
      "OutputUserData": {
        "type": "object",
        "properties": {
          "first_name": {
            "type": "string",
            "description": "The person's first name",
            "example": "John"
          },
          "middle_name": {
            "type": "string",
            "description": "The person's middle name"
          },
          "last_name": {
            "type": "string",
            "description": "The person's last name",
            "example": "Smith"
          },
          "date_of_birth": {
            "type": "string",
            "example": "1984-01-21",
            "description": "The person's date of birth in YYYY-MM-DD format"
          },
          "phone_number": {
            "type": "string",
            "example": "+13334445555",
            "description": "The person's phone number. Prefer phone number in E.164 format"
          },
          "email_address": {
            "type": "string",
            "example": "johnsmith@aiprise.com",
            "description": "The person's email address"
          },
          "ip_address": {
            "type": "string",
            "example": null,
            "description": "The person's ip address"
          },
          "address": {
            "$ref": "#/components/schemas/Address"
          },
          "identity": {
            "$ref": "#/components/schemas/OutputIdentity"
          },
          "selfie": {
            "type": "string",
            "example": "S3_URL",
            "description": "A S3 url of the person's selfie."
          },
          "smile_selfie": {
            "type": "string"
          },
          "selfie_video": {
            "type": "string",
            "example": "S3_URL",
            "description": "A S3 url of the person's selfie video."
          },
          "questions_response": {
            "type": "object",
            "additionalProperties": {
              "$ref": "#/components/schemas/QuestionGroup"
            },
            "description": "Questions responded by the user"
          },
          "documents": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/MediaFile"
            }
          }
        }
      },
      "UserInput": {
        "type": "object",
        "properties": {
          "user_data": {
            "$ref": "#/components/schemas/OutputUserData"
          },
          "additional_user_info": {
            "type": "object",
            "additionalProperties": {}
          }
        }
      },
      "UserVerificationResponseV2": {
        "type": "object",
        "properties": {
          "user_input": {
            "$ref": "#/components/schemas/UserInput"
          },
          "aiprise_summary": {
            "$ref": "#/components/schemas/AiPriseResponseSummaryV2"
          },
          "status": {
            "$ref": "#/components/schemas/AiPriseRunStatusV2"
          },
          "status_reasons": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/StatusReasonCodeEntry"
            }
          },
          "client_reference_id": {
            "type": "string",
            "example": ""
          },
          "client_reference_data": {
            "type": "object"
          },
          "created_at": {
            "type": "integer",
            "format": "int64",
            "example": 1658201633363
          },
          "template_id": {
            "type": "string",
            "example": "9fa9dcb3-1098-4740-b029-67bf4974271e"
          },
          "verification_session_id": {
            "type": "string",
            "example": "16f96070-6ce6-4b05-a3c2-3127a0673128"
          },
          "aml_info": {
            "$ref": "#/components/schemas/AMLInfo",
            "nullable": true
          },
          "id_info": {
            "$ref": "#/components/schemas/IDInfo",
            "nullable": true
          },
          "face_match_info": {
            "$ref": "#/components/schemas/FaceMatchInfo",
            "nullable": true
          },
          "face_liveness_info": {
            "$ref": "#/components/schemas/FaceLivenessInfo",
            "nullable": true
          },
          "phone_insights": {
            "$ref": "#/components/schemas/PhoneInsights"
          },
          "fraud_insights": {
            "$ref": "#/components/schemas/FraudInsights",
            "nullable": true
          },
          "lookup_info": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/LookupInfo"
            }
          },
          "time_elapsed_to_process": {
            "type": "string",
            "example": "104.451"
          },
          "time_elapsed_to_complete_session": {
            "type": "string",
            "example": "100.11"
          },
          "additional_info": {
            "$ref": "#/components/schemas/AdditionalInformationOutput",
            "nullable": true
          },
          "environment": {
            "type": "string",
            "nullable": true
          },
          "risk_info": {
            "$ref": "#/components/schemas/RiskInfo",
            "nullable": true
          },
          "aml_monitoring_info": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/AMLMonitoringUpdate"
            }
          }
        }
      },
      "UserVerificationUrlRequest": {
        "type": "object",
        "required": [
          "redirect_uri",
          "template_id"
        ],
        "properties": {
          "redirect_uri": {
            "type": "string",
            "description": "The URI to which AiPrise will redirect after the verification is complete."
          },
          "template_id": {
            "type": "string",
            "example": "b7daff11-9d1c-4916-bf5a-96e3041a9625",
            "description": "Every verification must have a template id associated with it. This is Provided to you by AiPrise."
          },
          "client_reference_id": {
            "type": "string",
            "example": "b79160f1-3ea5-4bf0-be43-43c4a2cf8810",
            "description": "An id you want to associate with your request."
          },
          "client_reference_data": {
            "type": "object",
            "description": "A JSON you want to associate with your request."
          },
          "callback_url": {
            "type": "string",
            "example": "https://yourwebsite.com/aiprise-callback"
          },
          "events_callback_url": {
            "type": "string",
            "example": "https://yourwebsite.com/aiprise-callback"
          },
          "user_data": {
            "$ref": "#/components/schemas/PartialUserData"
          },
          "additional_info": {
            "$ref": "#/components/schemas/AdditionalInfo"
          },
          "theme_options": {
            "$ref": "#/components/schemas/ThemeOptions"
          },
          "ui_options": {
            "$ref": "#/components/schemas/UIOptions"
          },
          "verification_options": {
            "$ref": "#/components/schemas/VerificationOptions"
          }
        }
      },
      "UserVerificationUrlResponse": {
        "type": "object",
        "properties": {
          "message": {
            "type": "string",
            "example": "Success"
          },
          "verification_url": {
            "type": "string",
            "example": "https://verify-sandbox.aiprise.com/?verification_session_id=123"
          },
          "verification_session_id": {
            "type": "string",
            "description": "The unique identifier for the verification session",
            "example": "abc123-def456-ghi789"
          },
          "session_expiry_time": {
            "type": "string",
            "description": "The expiration time for the verification session",
            "example": "2024-12-31T23:59:59Z"
          }
        }
      },
      "MediaInfo": {
        "type": "object",
        "properties": {
          "title": {
            "type": "string",
            "nullable": true,
            "description": "Title of the media"
          },
          "url": {
            "type": "string",
            "nullable": true,
            "description": "URL of the media"
          },
          "date": {
            "type": "string",
            "nullable": true,
            "description": "Date of the media"
          },
          "snippet": {
            "type": "string",
            "nullable": true,
            "description": "Short summary of the media"
          }
        }
      },
      "SourceDetails": {
        "type": "object",
        "properties": {
          "name": {
            "type": "string",
            "nullable": true,
            "description": "Name of the source"
          },
          "source_id": {
            "type": "string",
            "nullable": true,
            "description": "ID of the source"
          },
          "url": {
            "type": "string",
            "nullable": true,
            "description": "URL of the source"
          },
          "listing_started": {
            "type": "string",
            "nullable": true,
            "description": "Unix timestamp in seconds for when the source listing started"
          },
          "listing_ended": {
            "type": "string",
            "nullable": true,
            "description": "Unix timestamp in seconds for when the source listing ended"
          },
          "country_codes": {
            "type": "array",
            "items": {
              "type": "string"
            },
            "nullable": true,
            "description": "List of country codes associated with the source"
          },
          "aml_types": {
            "type": "array",
            "items": {
              "type": "string"
            },
            "nullable": true,
            "description": "List of AML types associated with the source"
          }
        }
      },
      "FieldTypeEnum": {
        "type": "string",
        "enum": [
          "date_of_birth",
          "place_of_birth",
          "date_of_death",
          "country_codes",
          "country_names",
          "url"
        ]
      },
      "FieldDetail": {
        "type": "object",
        "properties": {
          "name": {
            "type": "string"
          },
          "value": {
            "anyOf": [
              {
                "type": "string"
              },
              {
                "type": "object"
              },
              {
                "type": "array",
                "items": {
                  "type": "string"
                }
              }
            ]
          },
          "type": {
            "$ref": "#/components/schemas/FieldTypeEnum"
          }
        }
      },
      "AMLHit": {
        "type": "object",
        "properties": {
          "hit_type": {
            "type": "string",
            "nullable": true,
            "description": "Type of AML hit"
          },
          "source_details": {
            "$ref": "#/components/schemas/SourceDetails",
            "nullable": true,
            "description": "Details of the source where the AML hit was found"
          },
          "fields": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/FieldDetail"
            },
            "nullable": true,
            "description": "List of fields associated with the AML hit"
          },
          "media": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/MediaInfo"
            },
            "nullable": true,
            "description": "List of media associated with the AML hit"
          }
        }
      },
      "EntityHit": {
        "type": "object",
        "properties": {
          "entity_type": {
            "type": "string",
            "enum": [
              "PERSON",
              "COMPANY",
              "ORGANISATION",
              "UNKNOWN"
            ]
          },
          "name": {
            "type": "string"
          },
          "name_match_score": {
            "type": "number",
            "format": "float",
            "minimum": 0,
            "maximum": 100
          },
          "also_known_as": {
            "type": "array",
            "items": {
              "type": "string"
            }
          },
          "associates": {
            "type": "array",
            "items": {
              "type": "object",
              "properties": {
                "name": {
                  "type": "string"
                },
                "association": {
                  "type": "string"
                }
              }
            }
          },
          "aml_hits": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/AMLHit"
            }
          },
          "date_of_birth": {
            "type": "string",
            "nullable": true
          },
          "date_of_birth_match_score": {
            "type": "number",
            "format": "float",
            "minimum": 0,
            "maximum": 100,
            "nullable": true
          },
          "id": {
            "type": "string"
          },
          "resolution_status": {
            "type": "string"
          },
          "history": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/AMLEntityHitHistory"
            }
          },
          "is_deleted": {
            "type": "boolean"
          },
          "additional_fields": {
            "type": "object",
            "description": "Additional fields as key-value pairs."
          }
        }
      },
      "EntityHitWithoutHistory": {
        "type": "object",
        "properties": {
          "entity_type": {
            "type": "string",
            "enum": [
              "PERSON",
              "COMPANY",
              "ORGANISATION",
              "UNKNOWN"
            ]
          },
          "name": {
            "type": "string"
          },
          "name_match_score": {
            "type": "number",
            "format": "float",
            "minimum": 0,
            "maximum": 100
          },
          "also_known_as": {
            "type": "array",
            "items": {
              "type": "string"
            }
          },
          "aml_hits": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/AMLHit"
            }
          },
          "date_of_birth": {
            "type": "string",
            "nullable": true
          },
          "date_of_birth_match_score": {
            "type": "number",
            "format": "float",
            "minimum": 0,
            "maximum": 100,
            "nullable": true
          },
          "id": {
            "type": "string"
          },
          "resolution_status": {
            "type": "string"
          },
          "is_deleted": {
            "type": "boolean"
          },
          "additional_fields": {
            "type": "object",
            "description": "Additional fields as key-value pairs."
          }
        }
      },
      "AMLEntityHitHistory": {
        "type": "object",
        "properties": {
          "created_at": {
            "type": "integer"
          },
          "entity_hit": {
            "$ref": "#/components/schemas/EntityHitWithoutHistory"
          },
          "event": {
            "$ref": "#/components/schemas/AMLEntityHitHistoryEvent"
          }
        }
      },
      "AMLEntityHitHistoryEvent": {
        "type": "string",
        "enum": [
          "ADDED",
          "UPDATED",
          "REMOVED",
          "MONITORING_UPDATE"
        ]
      },
      "AMLInfo": {
        "type": "object",
        "properties": {
          "status": {
            "$ref": "#/components/schemas/AiPriseRunStatusV2"
          },
          "status_reasons": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/StatusReasonCodeEntry"
            }
          },
          "entity_hits": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/EntityHit"
            }
          },
          "result": {
            "$ref": "#/components/schemas/AiPriseResultStatusV2"
          },
          "warnings": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/WarningEntry"
            }
          },
          "num_hits": {
            "type": "number"
          },
          "aml_hit_summary": {
            "type": "object",
            "additionalProperties": {
              "type": "string"
            }
          },
          "section_id": {
            "type": "string"
          },
          "search_criteria": {
            "type": "object",
            "properties": {
              "search_term": {
                "type": "string",
                "nullable": true
              },
              "fuzziness_score": {
                "type": "number",
                "nullable": true
              },
              "exact_match": {
                "type": "boolean",
                "nullable": true
              },
              "birth_year": {
                "type": "number",
                "nullable": true
              }
            }
          }
        }
      },
      "DocumentDetails": {
        "type": "object",
        "properties": {
          "mrz_data": {
            "type": "object",
            "additionalProperties": true,
            "nullable": true
          },
          "barcode_data": {
            "type": "object",
            "additionalProperties": true,
            "nullable": true
          },
          "ocr_data": {
            "type": "object",
            "additionalProperties": true,
            "nullable": true
          }
        }
      },
      "IDInfo": {
        "type": "object",
        "properties": {
          "status": {
            "$ref": "#/components/schemas/AiPriseRunStatusV2"
          },
          "status_reasons": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/StatusReasonCodeEntry"
            }
          },
          "result": {
            "$ref": "#/components/schemas/AiPriseAPIResultStatusV2"
          },
          "warnings": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/WarningEntry"
            }
          },
          "id_type": {
            "$ref": "#/components/schemas/IDType"
          },
          "id_number": {
            "type": "string"
          },
          "id_expiry_date": {
            "type": "string"
          },
          "id_issue_date": {
            "type": "string"
          },
          "first_name": {
            "type": "string"
          },
          "middle_name": {
            "type": "string"
          },
          "last_name": {
            "type": "string"
          },
          "second_last_name": {
            "type": "string"
          },
          "full_name": {
            "type": "string"
          },
          "personal_number": {
            "type": "string"
          },
          "birth_date": {
            "type": "string"
          },
          "gender": {
            "type": "string"
          },
          "nationality": {
            "type": "string"
          },
          "nationality_code": {
            "type": "string"
          },
          "issue_country": {
            "type": "string"
          },
          "issue_country_code": {
            "type": "string"
          },
          "issue_state": {
            "type": "string"
          },
          "issue_state_code": {
            "type": "string"
          },
          "address": {
            "$ref": "#/components/schemas/ExtractedAddress",
            "nullable": true,
            "description": "Contains address extracted from the document"
          },
          "document_details": {
            "$ref": "#/components/schemas/DocumentDetails"
          },
          "lookup_details": {
            "type": "object"
          },
          "field_info": {
            "type": "object",
            "additionalProperties": {
              "$ref": "#/components/schemas/FieldInfo"
            },
            "nullable": true
          },
          "check_summary": {
            "type": "object"
          },
          "section_id": {
            "type": "string"
          }
        },
        "required": [
          "result"
        ]
      },
      "FaceMatchInfo": {
        "type": "object",
        "properties": {
          "status": {
            "$ref": "#/components/schemas/AiPriseRunStatusV2"
          },
          "status_reasons": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/StatusReasonCodeEntry"
            }
          },
          "result": {
            "$ref": "#/components/schemas/AiPriseAPIResultStatusV2"
          },
          "warnings": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/WarningEntry"
            }
          },
          "face_match_score": {
            "type": "number",
            "format": "float",
            "minimum": 0,
            "maximum": 100,
            "nullable": true
          },
          "check_summary": {
            "type": "object"
          },
          "section_id": {
            "type": "string"
          }
        },
        "required": [
          "result"
        ]
      },
      "FaceLivenessInfo": {
        "type": "object",
        "properties": {
          "status": {
            "$ref": "#/components/schemas/AiPriseRunStatusV2"
          },
          "status_reasons": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/StatusReasonCodeEntry"
            }
          },
          "result": {
            "$ref": "#/components/schemas/AiPriseAPIResultStatusV2"
          },
          "warnings": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/WarningEntry"
            }
          },
          "source": {
            "type": "string",
            "nullable": true
          },
          "check_summary": {
            "type": "object"
          },
          "section_id": {
            "type": "string"
          }
        },
        "required": [
          "result"
        ]
      },
      "SocialProfile": {
        "type": "object",
        "properties": {
          "platform": {
            "type": "string",
            "description": "Name of the social media platform (e.g. Facebook, Telegram, etc.)",
            "nullable": true
          },
          "registered": {
            "type": "boolean",
            "description": "Indicates whether the user is registered on the social media platform",
            "nullable": true
          },
          "name": {
            "type": "string",
            "description": "Name of the user's account on the social media platform",
            "nullable": true
          },
          "last_seen": {
            "type": "string",
            "format": "date-time",
            "description": "Timestamp indicating when the user was last seen on the social media platform",
            "nullable": true
          },
          "account_id": {
            "type": "string",
            "description": "Unique identifier of the user's account on the social media platform",
            "nullable": true
          }
        }
      },
      "SocialSummary": {
        "type": "object",
        "properties": {
          "age_on_social": {
            "type": "number",
            "description": "Age of the user on the social media platform",
            "nullable": true
          },
          "number_of_names_returned": {
            "type": "number",
            "description": "Number of names returned from social media platforms",
            "nullable": true
          },
          "number_of_photos_returned": {
            "type": "number",
            "description": "Number of photos returned from social media platforms",
            "nullable": true
          },
          "registered_consumer_electronics_profiles": {
            "type": "number",
            "description": "Number of registered consumer electronics profiles",
            "nullable": true
          },
          "registered_ecommerce_profiles": {
            "type": "number",
            "description": "Number of registered ecommerce profiles",
            "nullable": true
          },
          "registered_education_profiles": {
            "type": "number",
            "description": "Number of registered education profiles",
            "nullable": true
          },
          "registered_email_provider_profiles": {
            "type": "number",
            "description": "Number of registered email provider profiles",
            "nullable": true
          },
          "registered_entertainment_profiles": {
            "type": "number",
            "description": "Number of registered entertainment profiles",
            "nullable": true
          },
          "registered_financial_profiles": {
            "type": "number",
            "description": "Number of registered financial profiles",
            "nullable": true
          },
          "registered_messaging_profiles": {
            "type": "number",
            "description": "Number of registered messaging profiles",
            "nullable": true
          },
          "registered_professional_profiles": {
            "type": "number",
            "description": "Number of registered professional profiles",
            "nullable": true
          },
          "registered_profiles": {
            "type": "number",
            "description": "Total number of registered profiles",
            "nullable": true
          },
          "registered_social_media_profiles": {
            "type": "number",
            "description": "Number of registered social media profiles",
            "nullable": true
          },
          "registered_travel_profiles": {
            "type": "number",
            "description": "Number of registered travel profiles",
            "nullable": true
          }
        }
      },
      "PortEvent": {
        "type": "object",
        "properties": {
          "from_carrier": {
            "type": "string",
            "description": "Name of the carrier that the number is being transferred from."
          },
          "to_carrier": {
            "type": "string",
            "description": "Name of the carrier that the number is being transferred to."
          },
          "port_date": {
            "type": "string",
            "format": "date-time",
            "description": "The date the number ported."
          }
        }
      },
      "PersonAddress": {
        "type": "object",
        "properties": {
          "address_line_1": {
            "type": "string"
          },
          "address_line_2": {
            "type": "string"
          },
          "address_city": {
            "type": "string"
          },
          "address_country": {
            "type": "string"
          },
          "latitude": {
            "type": "string"
          },
          "longitude": {
            "type": "string"
          },
          "last_seen": {
            "type": "string",
            "format": "date-time"
          },
          "postal_code": {
            "type": "string"
          },
          "address_state": {
            "type": "string"
          },
          "valid_since": {
            "type": "string",
            "format": "date-time"
          }
        }
      },
      "PersonField": {
        "type": "object",
        "properties": {
          "last_seen": {
            "type": "string",
            "format": "date-time"
          },
          "valid_since": {
            "type": "string",
            "format": "date-time"
          },
          "value": {
            "type": "string"
          }
        }
      },
      "Person": {
        "type": "object",
        "properties": {
          "names": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/PersonField"
            }
          },
          "genders": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/PersonField"
            }
          },
          "date_of_births": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/PersonField"
            }
          },
          "addresses": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/PersonAddress"
            }
          },
          "email_address": {
            "$ref": "#/components/schemas/PersonField"
          },
          "ip_addresses": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/PersonField"
            }
          },
          "national_id": {
            "type": "string"
          },
          "phone_number": {
            "type": "string"
          }
        }
      },
      "PhoneInsights": {
        "type": "object",
        "properties": {
          "status": {
            "$ref": "#/components/schemas/AiPriseRunStatusV2"
          },
          "status_reasons": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/StatusReasonCodeEntry"
            }
          },
          "result": {
            "$ref": "#/components/schemas/AiPriseAPIResultStatusV2"
          },
          "warnings": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/WarningEntry"
            }
          },
          "phone_number": {
            "type": "string"
          },
          "phone_valid": {
            "type": "boolean"
          },
          "phone_type": {
            "type": "string"
          },
          "phone_disposable": {
            "type": "boolean"
          },
          "phone_active": {
            "type": "boolean"
          },
          "activation_date": {
            "type": "string"
          },
          "sim_type": {
            "type": "string"
          },
          "country": {
            "type": "string"
          },
          "original_carrier": {
            "type": "string"
          },
          "current_carrier": {
            "type": "string"
          },
          "port_history": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/PortEvent"
            }
          },
          "phone_is_spam": {
            "type": "boolean"
          },
          "social_profiles": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/SocialProfile"
            }
          },
          "persons": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/Person"
            }
          },
          "section_id": {
            "type": "string"
          }
        }
      },
      "IPLocation": {
        "type": "object",
        "properties": {
          "country": {
            "type": "string"
          },
          "country_code": {
            "type": "string"
          },
          "region": {
            "type": "string"
          },
          "city": {
            "type": "string"
          },
          "latitude": {
            "type": "number",
            "format": "float"
          },
          "longitude": {
            "type": "number",
            "format": "float"
          },
          "postal_code": {
            "type": "string"
          },
          "timezone": {
            "type": "string"
          },
          "geo_name_id": {
            "type": "integer"
          }
        }
      },
      "DeviceInsights": {
        "type": "object",
        "properties": {
          "status": {
            "$ref": "#/components/schemas/AiPriseRunStatusV2"
          },
          "status_reasons": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/StatusReasonCodeEntry"
            }
          },
          "result": {
            "$ref": "#/components/schemas/AiPriseAPIResultStatusV2"
          },
          "warnings": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/WarningEntry"
            }
          },
          "browser_name": {
            "type": "string"
          },
          "browser_major_version": {
            "type": "string"
          },
          "browser_full_version": {
            "type": "string"
          },
          "os": {
            "type": "string"
          },
          "os_version": {
            "type": "string"
          },
          "device": {
            "type": "string"
          },
          "user_agent": {
            "type": "string"
          },
          "incognito": {
            "type": "boolean"
          },
          "bot_status": {
            "type": "boolean"
          },
          "bot_type": {
            "type": "string"
          },
          "privacy_settings": {
            "type": "boolean"
          },
          "section_id": {
            "type": "string"
          }
        }
      },
      "RelatedSession": {
        "type": "object",
        "properties": {
          "result": {
            "$ref": "#/components/schemas/AiPriseAPIResultStatusV2"
          },
          "status": {
            "$ref": "#/components/schemas/AiPriseRunStatusV2"
          },
          "created_at": {
            "type": "integer"
          },
          "relation": {
            "type": "string"
          },
          "verification_session_id": {
            "type": "string"
          }
        }
      },
      "RelatedSessionInsights": {
        "type": "object",
        "properties": {
          "status": {
            "$ref": "#/components/schemas/AiPriseRunStatusV2"
          },
          "status_reasons": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/StatusReasonCodeEntry"
            }
          },
          "result": {
            "$ref": "#/components/schemas/AiPriseAPIResultStatusV2"
          },
          "warnings": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/WarningEntry"
            }
          },
          "related_sessions": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/RelatedSession"
            }
          },
          "section_id": {
            "type": "string"
          }
        }
      },
      "GeolocationInsights": {
        "type": "object",
        "properties": {
          "status": {
            "$ref": "#/components/schemas/AiPriseRunStatusV2"
          },
          "status_reasons": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/StatusReasonCodeEntry"
            }
          },
          "result": {
            "$ref": "#/components/schemas/AiPriseAPIResultStatusV2"
          },
          "warnings": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/WarningEntry"
            }
          },
          "latitude": {
            "type": "number"
          },
          "longitude": {
            "type": "number"
          },
          "accuracy": {
            "type": "string"
          },
          "altitude": {
            "type": "string"
          },
          "altitude_accuracy": {
            "type": "string"
          },
          "heading": {
            "type": "string"
          },
          "speed": {
            "type": "string"
          },
          "section_id": {
            "type": "string"
          }
        }
      },
      "IPInsights": {
        "type": "object",
        "properties": {
          "status": {
            "$ref": "#/components/schemas/AiPriseRunStatusV2"
          },
          "status_reasons": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/StatusReasonCodeEntry"
            }
          },
          "result": {
            "$ref": "#/components/schemas/AiPriseAPIResultStatusV2"
          },
          "warnings": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/WarningEntry"
            }
          },
          "location": {
            "$ref": "#/components/schemas/IPLocation"
          },
          "internet_service_provider": {
            "type": "string"
          },
          "connection_type": {
            "type": "string"
          },
          "autonomous_system_number": {
            "type": "integer"
          },
          "organization": {
            "type": "string"
          },
          "is_crawler": {
            "type": "boolean"
          },
          "host": {
            "type": "string"
          },
          "proxy": {
            "type": "boolean"
          },
          "vpn": {
            "type": "boolean"
          },
          "tor": {
            "type": "boolean"
          },
          "active_vpn": {
            "type": "boolean",
            "description": "Identifies active VPN connections used by popular VPN services and private VPN servers."
          },
          "active_tor": {
            "type": "boolean",
            "description": "Identifies active TOR exits on the TOR network."
          },
          "recent_abuse": {
            "type": "boolean"
          },
          "bot_status": {
            "type": "boolean"
          },
          "abuse_velocity": {
            "type": "string"
          },
          "unique_hash": {
            "type": "string"
          },
          "ip_address": {
            "type": "string"
          },
          "section_id": {
            "type": "string"
          }
        }
      },
      "DomainInfo": {
        "type": "object",
        "properties": {
          "domain_name": {
            "type": "string"
          },
          "top_level_domain": {
            "type": "string"
          },
          "registered": {
            "type": "boolean"
          },
          "company_name": {
            "type": "string"
          },
          "disposable": {
            "type": "boolean"
          },
          "free_provider": {
            "type": "boolean"
          },
          "dmarc_compliance": {
            "type": "boolean"
          },
          "spf_strict": {
            "type": "boolean"
          },
          "suspicious_top_level_domain": {
            "type": "boolean"
          },
          "custom": {
            "type": "boolean"
          }
        }
      },
      "Breach": {
        "type": "object",
        "properties": {
          "platform_name": {
            "type": "string"
          },
          "breach_date": {
            "type": "string",
            "format": "date-time"
          },
          "domain_name": {
            "type": "string"
          }
        }
      },
      "EmailInsights": {
        "type": "object",
        "properties": {
          "status": {
            "$ref": "#/components/schemas/AiPriseRunStatusV2"
          },
          "status_reasons": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/StatusReasonCodeEntry"
            }
          },
          "result": {
            "$ref": "#/components/schemas/AiPriseAPIResultStatusV2"
          },
          "warnings": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/WarningEntry"
            }
          },
          "email": {
            "type": "string",
            "nullable": true
          },
          "email_tenure": {
            "type": "number",
            "format": "float"
          },
          "domain_info": {
            "$ref": "#/components/schemas/DomainInfo"
          },
          "is_breached": {
            "type": "boolean"
          },
          "no_of_breaches": {
            "type": "integer"
          },
          "first_breach": {
            "type": "string",
            "format": "date-time"
          },
          "last_breach": {
            "type": "string",
            "format": "date-time"
          },
          "breaches": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/Breach"
            }
          },
          "persons": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/Person"
            }
          },
          "social_profiles": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/SocialProfile"
            }
          },
          "social_summary": {
            "$ref": "#/components/schemas/SocialSummary",
            "nullable": true
          },
          "section_id": {
            "type": "string"
          }
        }
      },
      "FraudInsights": {
        "type": "object",
        "properties": {
          "status": {
            "$ref": "#/components/schemas/AiPriseRunStatusV2"
          },
          "status_reasons": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/StatusReasonCodeEntry"
            }
          },
          "result": {
            "$ref": "#/components/schemas/AiPriseAPIResultStatusV2"
          },
          "warnings": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/WarningEntry"
            }
          },
          "email_insights": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/EmailInsights"
            }
          },
          "phone_insights": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/PhoneInsights"
            }
          },
          "ip_insights": {
            "$ref": "#/components/schemas/IPInsights"
          },
          "device_insights": {
            "$ref": "#/components/schemas/DeviceInsights"
          },
          "related_session_insights": {
            "$ref": "#/components/schemas/RelatedSessionInsights"
          },
          "geolocation_insights": {
            "$ref": "#/components/schemas/GeolocationInsights"
          },
          "section_id": {
            "type": "string"
          }
        }
      },
      "AiPriseResultStatusV2": {
        "type": "string",
        "nullable": true,
        "example": "APPROVED",
        "enum": [
          "APPROVED",
          "DECLINED",
          "REVIEW",
          "UNKNOWN"
        ],
        "description": "The overall workflow result status for verification sessions/cases."
      },
      "AiPriseRunStatusV2": {
        "type": "string",
        "nullable": true,
        "example": "RUNNING",
        "enum": [
          "NOT_STARTED",
          "RUNNING",
          "PENDING",
          "FAILED",
          "COMPLETED",
          "SUBMITTED"
        ],
        "description": "The overall workflow result status."
      },
      "StatusReasonCodeEntry": {
        "type": "object",
        "properties": {
          "code": {
            "type": "string"
          },
          "message": {
            "type": "string"
          }
        }
      },
      "WarningEntry": {
        "type": "object",
        "properties": {
          "code": {
            "$ref": "#/components/schemas/AiPriseReasonCode"
          },
          "message": {
            "type": "string",
            "nullable": true
          },
          "warning_id": {
            "type": "string"
          },
          "resolution_status": {
            "type": "string"
          }
        }
      },
      "AdditionalInformationOutput": {
        "type": "array",
        "items": {
          "$ref": "#/components/schemas/AdditionalInfoBlob"
        }
      },
      "AdditionalInfoResponseType": {
        "type": "string",
        "enum": [
          "ID_INFO",
          "LOOKUP_DATA",
          "ADDRESS_VERIFICATION",
          "DOCUMENT_INSIGHTS"
        ]
      },
      "AdditionalInfoBlob": {
        "type": "object",
        "properties": {
          "additional_info_type": {
            "type": "string"
          },
          "additional_info_response_type": {
            "$ref": "#/components/schemas/AdditionalInfoResponseType"
          },
          "data": {
            "oneOf": [
              {
                "$ref": "#/components/schemas/IDInfo"
              },
              {
                "$ref": "#/components/schemas/AddressVerificationInfo"
              },
              {
                "$ref": "#/components/schemas/DocumentInsights"
              },
              {
                "type": "object"
              }
            ]
          }
        }
      },
      "AiPriseReasonCode": {
        "type": "string",
        "enum": [
          "UNRECOGNIZED_DOCUMENT",
          "UNREADABLE_DOCUMENT",
          "DOCUMENT_NOT_FOUND",
          "DOCUMENT_EXPIRED",
          "DOCUMENT_FRONT_BACK_MISMATCH",
          "DOCUMENT_DAMAGED",
          "DOCUMENT_FRONT_MISSING",
          "DOCUMENT_BACK_MISSING",
          "DOCUMENT_CROPPED",
          "DOCUMENT_FACE_NOT_FOUND",
          "INVALID_DOCUMENT_DETAILS",
          "DOCUMENT_TYPE_MISMATCH",
          "UNABLE_TO_EXTRACT_DOCUMENT_METADATA",
          "MISSING_EXPIRY_DATE",
          "MISSING_ISSUE_DATE",
          "MISSING_BIRTH_DATE",
          "MISSING_DOCUMENT_NUMBER",
          "MISSING_PERSONAL_NUMBER",
          "MISSING_ADDRESS",
          "MISSING_POSTCODE",
          "MISSING_NAME",
          "MISSING_GENDER",
          "MISSING_NATIONALITY",
          "MISSING_DOCUMENT_DETAILS",
          "DOCUMENT_FRONT_OR_BACK_MISSING",
          "NAME_VERIFICATION_FAILED",
          "ID_DATA_MISMATCH",
          "ID_TYPE_MISMATCH",
          "ID_NOT_ALLOWED",
          "ID_COUNTRY_MISMATCH",
          "BIRTH_DATE_MISMATCH",
          "ADDRESS_MISMATCH",
          "DATABASE_LOOKUP_ISSUE",
          "DOCUMENT_PHOTO_OF_PHOTO",
          "SCREEN_DETECTED",
          "IMAGE_FORGED_EDITED",
          "FEATURE_VERIFICATION_FAILED",
          "FAKE_ID",
          "DOCUMENT_FOUND_ON_INTERNET",
          "ARTIFICIAL_IMAGE",
          "ARTIFICIAL_TEXT",
          "TEXT_FORGERY",
          "IMAGE_TOO_SMALL",
          "GLARE_DETECTED",
          "IMAGE_TOO_BLURRY",
          "CHECK_DIGIT_FAILED",
          "PRINTOUT_DETECTED",
          "BLACK_WHITE_DOCUMENT",
          "FACE_NOT_FOUND",
          "MULTIPLE_FACES",
          "FACE_MISMATCH",
          "LOW_FACE_SIMILARITY",
          "FACE_IDENTICAL",
          "FACE_NOT_LIVE",
          "FACE_LIVENESS_REVIEW_REQUIRED",
          "FACE_PHOTO_OF_PHOTO",
          "FACE_COVERED",
          "FACE_EDITED",
          "FACE_BLUR",
          "FACE_TOO_CLOSE",
          "FACE_CROPPED",
          "FACE_TOO_SMALL",
          "FACE_ANGLE_TOO_LARGE",
          "FACE_DEEPFAKE",
          "ID_NUMBER_NOT_VERIFIED",
          "INVALID_ID_NUMBER_SUPPLIED",
          "LOOKUP_SOURCE_DOWN",
          "LOOKUP_FACE_NOT_FOUND",
          "ID_PREVIOUSLY_SEEN",
          "AGE_UNDER_18",
          "AML_MATCH",
          "API_DATA_REQUIREMENTS_NOT_MET",
          "IP_ADDRESS_SUSPICIOUS",
          "SUSPICIOUS_USER_BEHAVIOUR",
          "GEOLOCATION_MISMATCH",
          "COMPANY_NOT_FOUND",
          "COMPANY_NUMBER_NOT_VALID_FORMAT",
          "COMPANY_PREVIOUSLY_SEEN",
          "CUSTOM_ERROR_CODE",
          "DOCUMENT_TAMPERED",
          "DOCUMENT_REQUIRES_REVIEW",
          "DOCUMENT_TOO_FAR",
          "DOCUMENT_SIZE_TOO_LARGE",
          "BROKEN_DOCUMENT",
          "INVALID_FILE_EXTENSION",
          "FILE_TOO_LARGE",
          "FACE_LIVENESS_TOO_FAR_FROM_CAMERA",
          "FACE_PREVIOUSLY_ONBOARDED",
          "ADDRESS_DOCUMENT_MISSING_ADDRESS",
          "ADDRESS_DOCUMENT_ADDRESS_MISMATCH",
          "ADDRESS_DOCUMENT_MISSING_NAME",
          "ADDRESS_DOCUMENT_NAME_VERIFICATION_FAILED",
          "ADDRESS_DOCUMENT_MISSING_ISSUE_DATE",
          "ADDRESS_DOCUMENTS_NOT_SAME",
          "EXPIRY_DATE_MISSING_USING_ISSUE_DATE",
          "EXPIRY_AND_ISSUE_DATE_MISSING",
          "ADDRESS_DOCUMENT_DOCUMENT_EXPIRED",
          "ADDRESS_DOCUMENT_DOCUMENT_NOT_ISSUED_RECENTLY",
          "ADDRESS_DOCUMENT_UNRECOGNIZED_DOCUMENT",
          "TOR_DETECTED",
          "VPN_DETECTED",
          "BOT_DETECTED",
          "BROWSER_PREVIOUSLY_SEEN",
          "IP_ADDRESS_PREVIOUSLY_SEEN",
          "PRIVACY_SETTINGS_BLOCKED",
          "BUSINESS_ID_MISMATCH",
          "BUSINESS_INFO_NAME_MISMATCH",
          "REGISTRATION_STATUS_INACTIVE",
          "UNABLE_TO_EXTRACT_DATA",
          "EMAIL_NOT_FOUND",
          "PHONE_NUMBER_NOT_FOUND",
          "BROWSER_PREVIOUSLY_ATTEMPTED",
          "IP_ADDRESS_PREVIOUSLY_ATTEMPTED",
          "ID_PREVIOUSLY_ATTEMPTED",
          "BACKGROUND_CHECK_ALERT",
          "BUSINESS_ID_NOT_VERIFIED",
          "MRZ_DATA_MISMATCH",
          "SELFIE_VIDEO_FRAUD",
          "SELFIE_VIDEO_MISSING",
          "VIDEO_INJECTION_DETECTED",
          "VIRTUAL_CAMERA_DETECTED",
          "ADDRESS_ZIPCODE_MISMATCH",
          "FACE_AGE_MISMATCH",
          "NO_GLARES_DETECTED",
          "KEYWORD_MATCHES_FOUND",
          "HIGH_RISK_INDUSTRY_MATCHES_FOUND",
          "IMAGE_IN_FOCUS",
          "GOOD_IMAGE_RESOLUTION",
          "GOOD_IMAGE_COLOR",
          "GOOD_IMAGE_PERSPECTIVE",
          "DOCUMENT_FULLY_PRESENT",
          "PORTRAIT_PRESENT_IN_IMAGE",
          "GOOD_IMAGE_BRIGHTNESS",
          "NO_OCCLUSION_DETECTED",
          "GOOD_IMAGE_QUALITY",
          "IMAGE_DOCUMENT_TYPE_DETECTED",
          "IMAGE_TEXT_FIELDS_VERIFIED",
          "PO_BOX_DETECTED",
          "PARKED_WEBSITE",
          "SOCIAL_MEDIA_BROKEN_LINKS",
          "SOCIAL_MEDIA_BUSINESS_MISMATCH",
          "INCONSISTENCIES_IN_WEBSITE_CONTENT",
          "STOCK_IMAGES_FOUND",
          "PLACEHOLDER_TEXT_FOUND_IN_WEBSITE_CONTENT",
          "WEBSITE_NOT_FOUND",
          "WEBSITE_PARKED",
          "WEBSITE_REDIRECTED",
          "WEBSITE_GENERIC",
          "WEBSITE_NOT_AUTHENTIC",
          "BUSINESS_DESCRIPTION_MISMATCH",
          "TERMS_AND_CONDITIONS_NOT_FOUND",
          "TERMS_AND_CONDITIONS_FAILED",
          "INVALID_WEBSITE_SSL",
          "BUSINESS_GEOLOCATION_MISMATCH",
          "EMAIL_ADDRESS_MISMATCH",
          "PHONE_NUMBER_MISMATCH",
          "PROHIBITED_INDUSTRY_MATCHES_FOUND",
          "WEBSITE_URL_AND_BUSINESS_NAME_MISMATCH",
          "BUSINESS_COUNTRY_MISMATCH",
          "BUSINESS_WEBSITE_MISMATCH",
          "BUSINESS_FORMATION_DATE_MISMATCH",
          "BUSINESS_TAX_ID_MISMATCH"
        ]
      },
      "AiPriseAPIResultStatusV2": {
        "type": "string",
        "enum": [
          "APPROVED",
          "DECLINED",
          "REVIEW",
          "UNKNOWN",
          "FOUND",
          "NOT_FOUND"
        ],
        "description": "The result status of an API request. Possible values are `APPROVED`, `DECLINED`, `REVIEW`, `UNKNOWN`, `FOUND`, and `NOT_FOUND`.",
        "example": "APPROVED"
      },
      "IDType": {
        "type": "string",
        "enum": [
          "NATIONAL_ID",
          "ID_CARD",
          "PASSPORT",
          "DRIVER_LICENSE",
          "RESIDENT_CARD",
          "VISA",
          "VOTER_ID",
          "TAX_ID",
          "COLOMBIA_PPT",
          "GHANA_CARD",
          "GHANA_SSNIT_CARD",
          "KENYA_ALIEN_CARD",
          "NATIONAL_ID_NUMBER",
          "PASSPORT_NUMBER",
          "BVN",
          "VOTER_ID_NUMBER",
          "DRIVER_LICENSE_NUMBER",
          "TAX_ID_NUMBER",
          "NIN",
          "SSN9",
          "SSN4",
          "SSNIT",
          "KRA_PIN",
          "ALIEN_CARD_NUMBER",
          "COLOMBIA_PPT_NUMBER",
          "GHANA_CARD_NUMBER"
        ]
      },
      "FieldDataInfoMatchtype": {
        "type": "string",
        "enum": [
          "NIN",
          "CURP",
          "DRIVER_LICENSE",
          "VOTER_ID",
          "BVN",
          "PASSPORT",
          "AADHAAR",
          "PAN",
          "MRZ",
          "BARCODE",
          "SSNIT",
          "NATIONAL_ID",
          "RFC"
        ]
      },
      "FieldInfo": {
        "type": "object",
        "properties": {
          "matched": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/FieldDataInfoMatchtype"
            }
          },
          "unmatched": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/FieldDataInfoMatchtype"
            }
          }
        }
      },
      "AddressVerificationInfo": {
        "type": "object",
        "properties": {
          "status": {
            "$ref": "#/components/schemas/AiPriseRunStatusV2"
          },
          "status_reasons": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/StatusReasonCodeEntry"
            }
          },
          "result": {
            "$ref": "#/components/schemas/AiPriseAPIResultStatusV2"
          },
          "warnings": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/WarningEntry"
            }
          },
          "extracted_address": {
            "$ref": "#/components/schemas/ExtractedAddress"
          },
          "document_issue_date": {
            "type": "string",
            "nullable": true
          },
          "document_expiry_date": {
            "type": "string",
            "nullable": true
          },
          "first_name": {
            "type": "string",
            "nullable": true
          },
          "middle_name": {
            "type": "string",
            "nullable": true
          },
          "last_name": {
            "type": "string",
            "nullable": true
          },
          "full_name": {
            "type": "string",
            "nullable": true
          },
          "name_match": {
            "type": "boolean",
            "nullable": true
          },
          "address_match": {
            "type": "boolean",
            "nullable": true
          },
          "document_type": {
            "type": "string",
            "nullable": true
          },
          "section_id": {
            "type": "string"
          },
          "document_class_id": {
            "type": "string"
          },
          "document_class_type": {
            "type": "string"
          },
          "document_metadata": {
            "$ref": "#/components/schemas/DocumentMetadata"
          },
          "info_indicators": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/AddressVerificationIndicator"
            }
          },
          "risk_indicators": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/AddressVerificationIndicator"
            }
          },
          "trust_indicators": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/AddressVerificationIndicator"
            }
          }
        }
      },
      "AddressVerificationIndicator": {
        "type": "object",
        "properties": {
          "id": {
            "type": "string"
          },
          "category": {
            "type": "string"
          },
          "title": {
            "type": "string"
          },
          "description": {
            "type": "string"
          },
          "indicator_attributes": {
            "type": "object"
          },
          "metadata": {
            "type": "object"
          }
        }
      },
      "DocumentInsightsIndicator": {
        "type": "object",
        "properties": {
          "id": {
            "type": "string"
          },
          "category": {
            "type": "string"
          },
          "title": {
            "type": "string"
          },
          "description": {
            "type": "string"
          },
          "indicator_attributes": {
            "type": "object"
          },
          "metadata": {
            "type": "object"
          }
        }
      },
      "DocumentMetadata": {
        "type": "object",
        "properties": {
          "producer": {
            "type": "string"
          },
          "creator": {
            "type": "string"
          },
          "creation_date": {
            "type": "string"
          },
          "modification_date": {
            "type": "string"
          },
          "author": {
            "type": "string"
          },
          "title": {
            "type": "string"
          },
          "keywords": {
            "type": "array",
            "items": {
              "type": "string"
            }
          },
          "subject": {
            "type": "string"
          }
        }
      },
      "DocumentInsights": {
        "type": "object",
        "properties": {
          "status": {
            "$ref": "#/components/schemas/AiPriseRunStatusV2"
          },
          "status_reasons": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/StatusReasonCodeEntry"
            }
          },
          "result": {
            "$ref": "#/components/schemas/AiPriseAPIResultStatusV2"
          },
          "warnings": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/WarningEntry"
            }
          },
          "info_indicators": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/DocumentInsightsIndicator"
            }
          },
          "risk_indicators": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/DocumentInsightsIndicator"
            }
          },
          "trust_indicators": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/DocumentInsightsIndicator"
            }
          },
          "document_metadata": {
            "$ref": "#/components/schemas/DocumentMetadata"
          },
          "address_verification_info": {
            "$ref": "#/components/schemas/AddressVerificationInfo"
          },
          "document_class_id": {
            "type": "string"
          },
          "document_class_type": {
            "type": "string"
          },
          "document_class_variant": {
            "type": "string"
          },
          "section_id": {
            "type": "string"
          },
          "document_data": {
            "type": "object",
            "additionalProperties": {}
          }
        }
      },
      "Question": {
        "type": "object",
        "properties": {
          "question": {
            "type": "string",
            "nullable": true
          },
          "answer": {
            "type": "string",
            "nullable": true
          },
          "answer_file": {
            "type": "string",
            "nullable": true
          },
          "order": {
            "type": "number",
            "nullable": true
          },
          "answer_type": {
            "nullable": true,
            "$ref": "#/components/schemas/QuestionType"
          },
          "answer_language": {
            "type": "string",
            "nullable": true
          }
        }
      },
      "QuestionGroupMetaData": {
        "type": "object",
        "properties": {
          "template_id": {
            "type": "string"
          },
          "verification_session_id": {
            "type": "string"
          },
          "updated_at": {
            "type": "integer"
          }
        }
      },
      "QuestionGroup": {
        "type": "object",
        "additionalProperties": {
          "oneOf": [
            {
              "$ref": "#/components/schemas/Question"
            },
            {
              "type": "string"
            },
            {
              "$ref": "#/components/schemas/QuestionGroupMetaData"
            }
          ]
        }
      },
      "LookupInfo": {
        "type": "object",
        "properties": {
          "status": {
            "$ref": "#/components/schemas/AiPriseRunStatusV2",
            "nullable": true
          },
          "status_reasons": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/StatusReasonCodeEntry"
            },
            "nullable": true
          },
          "result": {
            "$ref": "#/components/schemas/AiPriseResultStatusV2",
            "nullable": true
          },
          "warnings": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/WarningEntry"
            },
            "nullable": true
          },
          "database_name": {
            "type": "string",
            "nullable": true
          },
          "title": {
            "type": "string",
            "nullable": true
          },
          "data": {
            "type": "object",
            "nullable": true
          },
          "section_id": {
            "type": "string"
          }
        }
      },
      "RiskLevelEnum": {
        "type": "string",
        "nullable": true,
        "example": null,
        "enum": [
          "PROHIBITED",
          "HIGH",
          "MEDIUM",
          "LOW"
        ]
      },
      "EvaluatedRiskRuleStatusEnum": {
        "type": "string",
        "nullable": true,
        "example": null,
        "enum": [
          "PASSED",
          "NOT_PASSED",
          "FAILED"
        ]
      },
      "EvaluatedRiskRule": {
        "type": "object",
        "properties": {
          "rule_name": {
            "type": "string",
            "nullable": true
          },
          "result": {
            "$ref": "#/components/schemas/AiPriseResultStatusV2",
            "nullable": true
          },
          "score": {
            "type": "number",
            "nullable": true
          },
          "status": {
            "$ref": "#/components/schemas/EvaluatedRiskRuleStatusEnum",
            "nullable": true
          }
        }
      },
      "AMLMonitoringUpdate": {
        "type": "object",
        "properties": {
          "added_entity_hits": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/EntityHit"
            },
            "nullable": true
          },
          "removed_entity_hits": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/EntityHit"
            },
            "nullable": true
          },
          "updated_entity_hits": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/EntityHit"
            },
            "nullable": true
          },
          "created_at": {
            "type": "number",
            "nullable": true
          }
        }
      },
      "RiskInfoScoring": {
        "type": "object",
        "properties": {
          "score": {
            "type": "number",
            "nullable": true
          },
          "risk_level": {
            "$ref": "#/components/schemas/RiskLevelEnum",
            "nullable": true
          },
          "rules_evaluated": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/EvaluatedRiskRule"
            }
          },
          "section_id": {
            "type": "string",
            "nullable": true
          }
        }
      },
      "RiskInfoDecisioning": {
        "type": "object",
        "properties": {
          "result": {
            "$ref": "#/components/schemas/AiPriseResultStatusV2",
            "nullable": true
          },
          "rules_evaluated": {
            "type": "array",
            "items": {
              "$ref": "#/components/schemas/EvaluatedRiskRule"
            }
          },
          "section_id": {
            "type": "string",
            "nullable": true
          }
        }
      },
      "RiskInfo": {
        "type": "object",
        "properties": {
          "scoring": {
            "$ref": "#/components/schemas/RiskInfoScoring"
          },
          "decisioning": {
            "$ref": "#/components/schemas/RiskInfoDecisioning"
          }
        }
      },
      "MediaFile": {
        "type": "object",
        "properties": {
          "file_uuid": {
            "type": "string",
            "nullable": false
          },
          "file_name": {
            "type": "string",
            "nullable": false
          },
          "file_type": {
            "type": "string",
            "nullable": true
          },
          "file_s3_url": {
            "type": "string",
            "nullable": false
          }
        }
      },
      "ThemeOptions": {
        "type": "object",
        "properties": {
          "background": {
            "type": "string",
            "nullable": true,
            "enum": [
              "light",
              "dark"
            ]
          },
          "color_page": {
            "type": "string",
            "nullable": true,
            "example": "#5251FD"
          },
          "color_brand": {
            "type": "string",
            "nullable": true,
            "example": "#5251FD"
          },
          "color_brand_overlay": {
            "type": "string",
            "nullable": true,
            "example": "#5251FD"
          },
          "font_name": {
            "type": "string",
            "nullable": true,
            "example": "Plus Jakarta Sans"
          },
          "font_weights": {
            "type": "string",
            "nullable": true,
            "example": "300,400,500,600,700"
          },
          "button_border_radius": {
            "type": "string",
            "nullable": true,
            "example": "8px"
          },
          "button_padding": {
            "type": "string",
            "nullable": true,
            "example": "18px"
          },
          "button_font_size": {
            "type": "string",
            "nullable": true,
            "example": "16px"
          },
          "button_font_weight": {
            "type": "string",
            "nullable": true,
            "example": "400"
          },
          "input_border_radius": {
            "type": "string",
            "nullable": true,
            "example": "8px"
          },
          "input_padding": {
            "type": "string",
            "nullable": true,
            "example": "14px 18px"
          },
          "input_font_size": {
            "type": "string",
            "nullable": true,
            "example": "16px"
          },
          "input_font_weight": {
            "type": "string",
            "nullable": true,
            "example": "500"
          },
          "input_label_font_size": {
            "type": "string",
            "nullable": true,
            "example": "16px"
          },
          "input_label_font_weight": {
            "type": "string",
            "nullable": true,
            "example": "500"
          },
          "layout_border_radius": {
            "type": "string",
            "nullable": true,
            "example": "18px"
          },
          "modal_border_radius": {
            "type": "string",
            "nullable": true,
            "example": "12px"
          },
          "image_border_radius": {
            "type": "string",
            "nullable": true,
            "example": "12px"
          }
        }
      },
      "UIOptions": {
        "type": "object",
        "properties": {
          "common": {
            "type": "object",
            "properties": {
              "default_language": {
                "type": "string",
                "example": "en"
              }
            }
          },
          "id_verification_module": {
            "type": "object",
            "properties": {
              "allowed_country_code": {
                "type": "string",
                "example": "US",
                "description": "A 2-letter ISO country code that will be pre-selected in the onboarding UI."
              },
              "allowed_document_type": {
                "oneOf": [
                  {
                    "type": "array",
                    "items": {
                      "type": "string"
                    },
                    "example": [
                      "ID_CARD",
                      "PASSPORT"
                    ]
                  },
                  {
                    "type": "string",
                    "example": "ID_CARD"
                  }
                ],
                "description": "A list of document types that will be pre-selected in the onboarding UI. Type string is deprecated, please use array of string(s). Check the country-wise supported documents list here: https://docs.aiprise.com/docs/supported-documents"
              }
            }
          }
        }
      },
      "VerificationOptions": {
        "type": "object",
        "properties": {
          "aml_config": {
            "type": "object",
            "properties": {
              "exact_match": {
                "type": "number"
              },
              "fuzziness_score": {
                "type": "number"
              },
              "monitoring": {
                "type": "boolean"
              },
              "categories_filter": {
                "type": "array",
                "items": {
                  "type": "string"
                }
              },
              "search_profile": {
                "type": "string",
                "nullable": true
              }
            }
          }
        }
      },
      "QuestionType": {
        "type": "string",
        "enum": [
          "SINGLE_CHOICE",
          "MULTIPLE_CHOICE",
          "BOOLEAN",
          "TEXT",
          "FILE",
          "EMAIL",
          "PHONE_NUMBER",
          "SINGLE_SELECT",
          "MULTIPLE_SELECT",
          "DATE",
          "PARAGRAPH",
          "URL"
        ]
      }
    },
    "securitySchemes": {
      "apiKey": {
        "type": "apiKey",
        "in": "header",
        "name": "X-API-KEY",
        "description": "All requests must include the `X-API-KEY` header containing your API Key."
      }
    }
  },
  "security": [
    {
      "apiKey": []
    }
  ]
}
```