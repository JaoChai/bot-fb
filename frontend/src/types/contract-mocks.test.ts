import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import Ajv from 'ajv'
import addFormats from 'ajv-formats'
import { parse } from 'yaml'
import {
  conversationsListResponse,
  loginResponse,
  messagesListResponse,
} from '../../e2e/mock-data'

/**
 * Validates the e2e mock response bodies for the contract-covered operations
 * (login, listConversations, listConversationMessages) against the schemas in
 * contracts/openapi.yaml. The e2e fixtures are the wire shapes the smoke
 * journeys rely on, so they must match the contract the backend is tested
 * against (backend/tests/Feature/Contracts/AuthChatContractTest.php).
 */
const contractPath = resolve(process.cwd(), '../contracts/openapi.yaml')
const contract = parse(readFileSync(contractPath, 'utf8')) as Record<string, unknown>

function validatorFor(schemaPointer: string) {
  // Register each component under its pointer URI so internal $refs
  // (#/components/schemas/X) resolve between components, without ever
  // treating the whole OpenAPI document (with its openapi/info/paths keys)
  // as a schema under ajv strict mode.
  // allowUnionTypes: the contract uses OpenAPI 3.1 union types
  // (type: [array, "null"] etc.), which ajv accepts with this flag.
  const ajv = new Ajv({ strict: true, allErrors: true, allowUnionTypes: true })
  addFormats(ajv)
  const schemas = (contract.components as { schemas: Record<string, object> }).schemas
  for (const [name, schema] of Object.entries(schemas)) {
    ajv.addSchema(structuredClone(schema), `contract#/components/schemas/${name}`)
  }
  const validate = ajv.getSchema(`contract${schemaPointer}`)
  expect(validate, `schema ${schemaPointer} must exist in contracts/openapi.yaml`).toBeDefined()
  return validate!
}

const validateLogin = validatorFor('#/components/schemas/LoginResponse')
const validateConversationsList = validatorFor('#/components/schemas/ConversationListResponse')
const validateMessagesList = validatorFor('#/components/schemas/MessageListResponse')

function expectValid(validate: (data: unknown) => boolean, body: unknown) {
  const ok = validate(body)
  const errors = validate.errors ?? []
  expect(
    ok,
    `fixture must match the contract schema:\n${JSON.stringify(errors, null, 2)}`,
  ).toBe(true)
}

function expectInvalid(validate: (data: unknown) => boolean, body: unknown) {
  const ok = validate(body)
  expect(ok, 'fixture must FAIL validation').toBe(false)
}

describe('e2e login fixture vs contract', () => {
  it('login response matches the contract LoginResponse schema', () => {
    expectValid(validateLogin, loginResponse)
  })

  it('rejects a login envelope wrapped in data (wrong envelope shape)', () => {
    expectInvalid(validateLogin, { data: loginResponse })
  })

  it('rejects a login response missing token_type', () => {
    const withoutTokenType = structuredClone(loginResponse) as Record<string, unknown>
    delete withoutTokenType.token_type
    expectInvalid(validateLogin, withoutTokenType)
  })

  it('rejects a login user missing subscription_plan', () => {
    const broken = structuredClone(loginResponse) as Record<string, unknown>
    delete (broken.user as Record<string, unknown>).subscription_plan
    expectInvalid(validateLogin, broken)
  })
})

describe('e2e conversations list fixture vs contract', () => {
  it('conversations list response matches the contract ConversationListResponse schema', () => {
    expectValid(validateConversationsList, conversationsListResponse)
  })

  it('rejects a conversation missing unread_count', () => {
    const broken = structuredClone(conversationsListResponse) as Record<string, unknown>
    delete ((broken.data as Record<string, unknown>[])[0] as Record<string, unknown>)
      .unread_count
    expectInvalid(validateConversationsList, broken)
  })

  it('rejects meta without status_counts', () => {
    const broken = structuredClone(conversationsListResponse) as Record<string, unknown>
    delete (broken.meta as Record<string, unknown>).status_counts
    expectInvalid(validateConversationsList, broken)
  })
})

describe('e2e messages list fixture vs contract', () => {
  it('messages list response matches the contract MessageListResponse schema', () => {
    expectValid(validateMessagesList, messagesListResponse)
  })

  it('rejects a message missing created_at', () => {
    const broken = structuredClone(messagesListResponse) as Record<string, unknown>
    delete ((broken.data as Record<string, unknown>[])[0] as Record<string, unknown>)
      .created_at
    expectInvalid(validateMessagesList, broken)
  })
})
